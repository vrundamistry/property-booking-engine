<?php
/**
 * Minimal Shortcodes for Property Search and Featured Properties.
 * All CSS is in assets/css/pbe-frontend.css — no inline styles here.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Shortcodes {

    public function __construct() {
        // ... constructor is technically empty, but we hook from init() instead.
    }

    /**
     * Injects the availability data so JS frontend can instantly load it without AJAX.
     * Tracks injected properties to prevent duplicate script tags on the same page.
     */
    private function inject_availability_script( $property_id ) {
        static $injected = array();
        if ( isset( $injected[ $property_id ] ) ) {
            return '';
        }
        $injected[ $property_id ] = true;

        global $wpdb;
        $table_name = $wpdb->prefix . 'pbe_calendar_dates';
        $platform_id = get_post_meta($property_id, 'platform_property_id', true);
        
        $availability_data = $wpdb->get_results( $wpdb->prepare(
            "SELECT calendar_date AS date, status, min_nights, guests, cta, ctd FROM $table_name WHERE platform_property_id = %s AND calendar_date >= %s",
            $platform_id,
            date('Y-m-d')
        ), ARRAY_A );

        return '<script>window.pbeAvailabilityData = ' . wp_json_encode($availability_data) . ';</script>';
    }

    public function init() {
        add_shortcode( 'property_search',       array( $this, 'render_search_form' ) );
        add_shortcode( 'pbe_booking_widget',    array( $this, 'render_booking_widget' ) );
        add_shortcode( 'pbe_native_booking_widget', array( $this, 'render_native_booking_widget' ) );
        add_shortcode( 'pbe_featured_properties', array( $this, 'render_featured_properties' ) );
        add_shortcode( 'pbe_availability',       array( $this, 'render_availability_calendar' ) );
        add_action( 'wp_enqueue_scripts',       array( $this, 'enqueue_assets' ) );
    }

    /**
     * Enqueue frontend CSS whenever a shortcode is used on the page.
     * We use a flag set by the shortcode render methods.
     */
    public function enqueue_assets() {
        // Always enqueue on shortcode pages; template loader handles property pages.
        // We register here so theme / other pages can also dequeue if desired.
        $css_ver = file_exists( PBE_PLUGIN_DIR . 'assets/css/pbe-frontend.css' ) ? filemtime( PBE_PLUGIN_DIR . 'assets/css/pbe-frontend.css' ) : '2.1.0';
        wp_register_style(
            'pbe-frontend',
            PBE_PLUGIN_URL . 'assets/css/pbe-frontend.css',
            array(),
            $css_ver
        );

        $native_css_ver = file_exists( PBE_PLUGIN_DIR . 'assets/css/pbe-native-booking.css' ) ? filemtime( PBE_PLUGIN_DIR . 'assets/css/pbe-native-booking.css' ) : '1.0.0';
        wp_register_style(
            'pbe-native-booking',
            PBE_PLUGIN_URL . 'assets/css/pbe-native-booking.css',
            array('pbe-frontend'),
            $native_css_ver
        );

        $native_js_ver = file_exists( PBE_PLUGIN_DIR . 'assets/js/pbe-native-booking.js' ) ? filemtime( PBE_PLUGIN_DIR . 'assets/js/pbe-native-booking.js' ) : '1.0.0';
        wp_register_script(
            'pbe-native-booking-js',
            PBE_PLUGIN_URL . 'assets/js/pbe-native-booking.js',
            array('jquery', 'litepicker-js', 'stripe-js'),
            $native_js_ver,
            true
        );

        wp_register_script(
            'stripe-js',
            'https://js.stripe.com/v3/',
            array(),
            '3.0',
            false
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // SEARCH FORM  [property_search]
    // ─────────────────────────────────────────────────────────────────

    public function render_search_form( $atts ) {
        wp_enqueue_style( 'pbe-frontend' );

        $atts = shortcode_atts( array(
            'action' => '',
        ), $atts );

        if ( empty( $atts['action'] ) ) {
            // Find a page with the PBE listing template
            $listing_query = new WP_Query( array(
                'post_type'  => 'page',
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'pbe-property-listing.php',
                'posts_per_page' => 1,
            ) );
            if ( $listing_query->have_posts() ) {
                $atts['action'] = get_permalink( $listing_query->posts[0]->ID );
            } else {
                $atts['action'] = home_url( '/' ); // Fallback to home
            }
        }

        ob_start();
        ?>
        <div class="pbe-search-container">
            <form class="pbe-property-search-form" method="GET" action="<?php echo esc_url( $atts['action'] ); ?>">
                <?php if ( ! empty($_GET['pbe_beds']) ) : ?><input type="hidden" name="pbe_beds" value="<?php echo esc_attr($_GET['pbe_beds']); ?>"><?php endif; ?>
                <?php if ( ! empty($_GET['pbe_baths']) ) : ?><input type="hidden" name="pbe_baths" value="<?php echo esc_attr($_GET['pbe_baths']); ?>"><?php endif; ?>
                <?php if ( ! empty($_GET['pbe_guests']) ) : ?><input type="hidden" name="pbe_guests" value="<?php echo esc_attr($_GET['pbe_guests']); ?>"><?php endif; ?>
                <?php if ( ! empty($_GET['pbe_prop_type']) ) : ?><input type="hidden" name="pbe_prop_type" value="<?php echo esc_attr($_GET['pbe_prop_type']); ?>"><?php endif; ?>
                <?php if ( ! empty($_GET['pbe_tag']) ) : ?><input type="hidden" name="pbe_tag" value="<?php echo esc_attr($_GET['pbe_tag']); ?>"><?php endif; ?>
                
                <!-- Property Name Group -->
                <div class="pbe-sf-group">
                    <div class="pbe-sf-icon">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <div class="pbe-sf-text">
                        <label for="pbe-sf-name">PROPERTY NAME</label>
                        <input type="text" id="pbe-sf-name" name="p_name"
                               placeholder="Property Name"
                               value="<?php echo esc_attr( $_GET['p_name'] ?? '' ); ?>">
                    </div>
                </div>

                <div class="pbe-sf-divider"></div>

                <!-- Check In Group -->
                <div class="pbe-sf-group">
                    <div class="pbe-sf-icon">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </div>
                    <div class="pbe-sf-text">
                        <label for="pbe-sf-checkin">CHECK-IN DATE</label>
                        <input type="text" id="pbe-sf-checkin" name="checkin"
                               class="pbe-date-input"
                               placeholder="Add date"
                               value="<?php echo esc_attr( $_GET['checkin'] ?? '' ); ?>">
                    </div>
                </div>

                <div class="pbe-sf-divider"></div>

                <!-- Check Out Group -->
                <div class="pbe-sf-group">
                    <div class="pbe-sf-icon">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </div>
                    <div class="pbe-sf-text">
                        <label for="pbe-sf-checkout">CHECK-OUT DATE</label>
                        <input type="text" id="pbe-sf-checkout" name="checkout"
                               class="pbe-date-input"
                               placeholder="Add date"
                               value="<?php echo esc_attr( $_GET['checkout'] ?? '' ); ?>">
                    </div>
                </div>

                <div class="pbe-sf-divider"></div>

                <!-- Guests Group -->
                <div class="pbe-sf-group">
                    <div class="pbe-sf-icon">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </div>
                    <div class="pbe-sf-text">
                        <label>GUESTS</label>
                        <div class="pbe-custom-dropdown" id="pbe-guests-dropdown">
                            <div class="pbe-cd-trigger">
                                <span class="pbe-cd-label"><?php 
                                    $current_guests = $_GET['guests'] ?? '';
                                    echo $current_guests ? sprintf( _n( '%s Guest', '%s Guests', $current_guests, 'pbe' ), $current_guests ) : __( 'Add guests', 'pbe' ); 
                                ?></span>
                            </div>
                            <ul class="pbe-cd-options">
                                <li data-value=""><?php _e( 'Any Guests', 'pbe' ); ?></li>
                                <?php for ( $i = 2; $i <= 17; $i++ ) : ?>
                                    <li data-value="<?php echo $i; ?>" <?php echo ( $current_guests == $i ) ? 'class="active"' : ''; ?>>
                                        <?php printf( _n( '%s Guest', '%s Guests', $i, 'pbe' ), $i ); ?>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                            <input type="hidden" name="guests" id="pbe-sf-guests-val" value="<?php echo esc_attr( $current_guests ); ?>">
                        </div>
                    </div>
                </div>

                <button type="submit">Search</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the Booking Widget [pbe_booking_widget id="123"]
     */
    public function render_booking_widget( $atts ) {
        wp_enqueue_style( 'pbe-frontend' );
        wp_enqueue_script( 'pbe-frontend-js' );

        $atts = shortcode_atts( array(
            'id' => get_the_ID(),
        ), $atts );

        $property_id = intval( $atts['id'] );
        if ( ! $property_id || get_post_type( $property_id ) !== 'property' ) {
            return '';
        }

        // Get dynamic values from metadata
        $price      = get_post_meta( $property_id, 'price_per_night', true );
        $max_guests = get_post_meta( $property_id, 'max_guests', true );

        // Get search context from query string
        $checkin  = sanitize_text_field( $_GET['checkin']  ?? '' );
        $checkout = sanitize_text_field( $_GET['checkout'] ?? '' );
        $guests   = intval( $_GET['guests'] ?? '' );

        // If no guests passed, default to 2 or max
        if ( ! $guests ) {
            $guests = min( 2, (int) $max_guests );
        }

        ob_start();
        echo $this->inject_availability_script( $property_id );

        $template_file = PBE_PLUGIN_DIR . 'templates/booking-widget.php';
        
        // Allow theme override: your-theme/pbe-templates/booking-widget.php
        $theme_file = get_stylesheet_directory() . '/pbe-templates/booking-widget.php';
        if ( file_exists( $theme_file ) ) {
            $template_file = $theme_file;
        }

        if ( file_exists( $template_file ) ) {
            include $template_file;
        }

        return ob_get_clean();
    }

    /**
     * Renders the Native Booking Widget [pbe_native_booking_widget id="123"]
     */
    public function render_native_booking_widget( $atts ) {
        wp_enqueue_style( 'pbe-frontend' );
        wp_enqueue_style( 'litepicker-css' );
        wp_enqueue_style( 'pbe-native-booking' );
        
        wp_enqueue_script( 'litepicker-js' );
        wp_enqueue_script( 'pbe-native-booking-js' );

        $stripe_mode = get_option('pbe_stripe_mode', 'test');
        $pub_key = ($stripe_mode === 'live') ? get_option('pbe_stripe_live_pub') : get_option('pbe_stripe_test_pub');

        wp_localize_script( 'pbe-native-booking-js', 'pbe_ajax', array(
            'url'        => admin_url( 'admin-ajax.php' ),
            'stripe_key' => $pub_key,
            'currency'   => get_post_meta( intval( $atts['id'] ), 'currency', true ) ?: 'USD'
        ) );

        $atts = shortcode_atts( array(
            'id' => get_the_ID(),
        ), $atts );

        $property_id = intval( $atts['id'] );
        if ( ! $property_id || get_post_type( $property_id ) !== 'property' ) {
            return '';
        }

        // Get search context
        $checkin  = sanitize_text_field( $_GET['checkin']  ?? '' );
        $checkout = sanitize_text_field( $_GET['checkout'] ?? '' );
        $guests   = intval( $_GET['guests'] ?? 2 );

        ob_start();
        echo $this->inject_availability_script( $property_id );

        $template_file = PBE_PLUGIN_DIR . 'templates/native-booking-widget.php';
        if ( file_exists( $template_file ) ) {
            include $template_file;
        }
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────
    // FEATURED PROPERTIES  [pbe_featured_properties limit="3"]
    // ─────────────────────────────────────────────────────────────────

    public function render_featured_properties( $atts ) {
        wp_enqueue_style( 'pbe-frontend' );

        $atts = shortcode_atts( array(
            'limit' => 3,
        ), $atts );

        $args = array(
            'post_type'      => 'property',
            'posts_per_page' => intval( $atts['limit'] ),
            'post_status'    => 'publish',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => 'platform_source',
                    'value'   => get_option('pbe_active_platform', 'guesty'),
                    'compare' => '='
                ),
                array(
                    'key'     => 'is_active',
                    'value'   => '1',
                    'compare' => '='
                )
            )
        );

        $query = new WP_Query( $args );

        ob_start();
        ?>
        <div class="pbe-fp-wrapper">
            <div class="pbe-fp-header">
                <div class="pbe-fp-title-area">
                    <h2>Featured Properties</h2>
                    <p>Explore our most popular luxury destinations.</p>
                </div>
                <?php
                $listing_url = '#';
                $listing_query = new WP_Query( array(
                    'post_type'  => 'page',
                    'meta_key'   => '_wp_page_template',
                    'meta_value' => 'pbe-property-listing.php',
                    'posts_per_page' => 1,
                ) );
                if ( $listing_query->have_posts() ) {
                    $listing_url = get_permalink( $listing_query->posts[0]->ID );
                }
                ?>
                <a href="<?php echo esc_url( $listing_url ); ?>"
                   class="pbe-fp-view-all">View All &rsaquo;</a>
            </div>

            <div class="pbe-fp-grid">
                <?php if ( $query->have_posts() ) : ?>
                    <?php while ( $query->have_posts() ) : $query->the_post();
                        $pid      = get_the_ID();
                        $location = get_post_meta( $pid, 'full_address',       true ) ?: 'Luxury Destination';
                        $price    = get_post_meta( $pid, 'price_per_night',    true );
                        $img_url  = get_post_meta( $pid, 'featured_image_url', true );
                        if ( empty( $img_url ) || strpos( $img_url, 'data:image' ) !== false ) {
                            $img_url = PBE_PLUGIN_URL . 'assets/images/placeholder.svg';
                        }
                        $price_str = $price ? '$' . number_format( (float) $price ) : 'N/A';
                    ?>
                        <?php 
                            // Capture search context if present in URL
                            $search_params = array();
                            if ( ! empty( $_GET['checkin'] ) )  $search_params['checkin']  = sanitize_text_field( $_GET['checkin'] );
                            if ( ! empty( $_GET['checkout'] ) ) $search_params['checkout'] = sanitize_text_field( $_GET['checkout'] );
                            if ( ! empty( $_GET['guests'] ) )   $search_params['guests']   = intval( $_GET['guests'] );
                            
                            $book_link = add_query_arg( $search_params, get_permalink() );

                        // Gallery for slider
                        $gallery_json = get_post_meta( $pid, 'property_gallery_urls', true );
                        $gallery      = ! empty( $gallery_json ) ? json_decode( $gallery_json, true ) : array();

                        // Filter out common Guesty placeholders
                        $gallery = array_filter( (array) $gallery, function($url) {
                            return ( strpos($url, 'njmfgob91z7fiilhslkz.jpg') === false && ! empty( $url ) );
                        });

                        // Check featured image as well
                        if ( strpos($img_url, 'njmfgob91z7fiilhslkz.jpg') !== false ) {
                            $img_url = '';
                        }

                        if ( empty( $gallery ) && ! empty( $img_url ) ) {
                            $gallery = array( $img_url );
                        }

                        // Fallback to minimalist coming-soon image if still empty
                        if ( empty( $gallery ) ) {
                            $gallery = array( PBE_PLUGIN_URL . 'assets/images/coming-soon.png' );
                            $img_url = PBE_PLUGIN_URL . 'assets/images/coming-soon.png';
                        }

                        $gallery = array_slice( (array) $gallery, 0, 8 );
                        ?>
                        <div class="pbe-property-card" data-post-id="<?php echo esc_attr( $pid ); ?>">
                            <div class="pbe-card-img-wrap">
                                <?php if ( count( $gallery ) > 1 ) : ?>
                                    <div class="swiper pbe-card-swiper">
                                        <div class="swiper-wrapper">
                                            <?php foreach ( $gallery as $url ) : ?>
                                                <div class="swiper-slide">
                                                    <a href="<?php echo esc_url( $book_link ); ?>">
                                                        <img class="pbe-property-card-img" src="<?php echo esc_url( $url ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="swiper-pagination"></div>
                                        <div class="swiper-button-next"></div>
                                        <div class="swiper-button-prev"></div>
                                    </div>
                                <?php else : ?>
                                    <a href="<?php echo esc_url( $book_link ); ?>">
                                        <img class="pbe-property-card-img" src="<?php echo esc_url( $img_url ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="pbe-property-card-body">
                                <div class="pbe-card-top-meta">
                                    <span class="pbe-property-type"><?php echo esc_html( get_post_meta( $pid, 'property_type', true ) ?: 'Property' ); ?></span>
                                </div>
                                <h3 class="pbe-property-card-title">
                                    <a href="<?php echo esc_url( $book_link ); ?>"><?php the_title(); ?></a>
                                </h3>
                                <div class="pbe-property-meta-icons">
                                    <span title="Bedrooms"><?php echo PBE_Template_Loader::get_svg('bed'); ?> <?php echo esc_html( get_post_meta( $pid, 'bedrooms', true ) ?: '0' ); ?> Beds</span>
                                    <span title="Bathrooms"><?php echo PBE_Template_Loader::get_svg('bath'); ?> <?php echo esc_html( get_post_meta( $pid, 'bathrooms', true ) ?: '0' ); ?> Baths</span>
                                    <span title="Max Guests"><?php echo PBE_Template_Loader::get_svg('guests'); ?> <?php echo esc_html( get_post_meta( $pid, 'max_guests', true ) ?: '0' ); ?> Guests</span>
                                </div>
                                <div class="pbe-property-card-footer">
                                    <a href="<?php echo esc_url( $book_link ); ?>" class="pbe-view-btn">Book Now</a>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; wp_reset_postdata(); ?>
                <?php else : ?>
                    <p>No featured properties found. Please sync some properties first.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    // ─────────────────────────────────────────────────────────────────
    // AVAILABILITY CALENDAR  [pbe_availability rows="2" cols="2"]
    // ─────────────────────────────────────────────────────────────────
    public function render_availability_calendar( $atts ) {
        wp_enqueue_style( 'pbe-frontend' );
        wp_enqueue_script( 'pbe-frontend-js' );

        $atts = shortcode_atts( array(
            'id'           => get_the_ID(),
            'rows'         => 1,
            'cols'         => 2,
            'total_months' => 4,
            'title'        => 'Availability'
        ), $atts );

        $property_id = intval( $atts['id'] );
        if ( ! $property_id || get_post_type( $property_id ) !== 'property' ) {
            return '';
        }

        $platform_source = get_post_meta( $property_id, 'platform_source', true );

        ob_start();
        echo $this->inject_availability_script( $property_id );
        ?>
        <section class="pbe-availability-section">
            <div class="pbe-section-header">
                <div class="pbe-section-title-wrap">
                    <h2 class="pbe-single-heading"><?php echo esc_html( $atts['title'] ); ?></h2>
                    <p class="pbe-section-subtitle">Select dates in the booking widget to check total price.</p>
                </div>
                
                <div class="pbe-section-top-right">
                    <div class="pbe-cal-nav">
                        <button id="pbe-cal-prev" class="pbe-cal-nav-btn" disabled>&lsaquo;</button>
                        <button id="pbe-cal-next" class="pbe-cal-nav-btn">&rsaquo;</button>
                    </div>
                </div>
            </div>

            <div class="pbe-availability-container">
                <div id="pbe-inline-calendar" 
                     data-property-id="<?php echo esc_attr( $property_id ); ?>"
                     data-platform-source="<?php echo esc_attr( $platform_source ); ?>"
                     data-rows="<?php echo esc_attr( $atts['rows'] ); ?>"
                     data-cols="<?php echo esc_attr( $atts['cols'] ); ?>"
                     data-total-months="<?php echo esc_attr( $atts['total_months'] ); ?>">
                    <!-- JS will render calendar here -->
                </div>

                <div class="pbe-cal-legend">
                    <div class="pbe-legend-item">
                        <span class="pbe-legend-swatch available"></span>
                        <span>Available</span>
                    </div>
                    <div class="pbe-legend-item">
                        <span class="pbe-legend-swatch booked"></span>
                        <span>Booked</span>
                    </div>
                    <div class="pbe-legend-item">
                        <span class="pbe-legend-swatch restricted"></span>
                        <span>Restricted</span>
                    </div>
                </div>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }
}
