<?php
/**
 * Plugin Template: Property Listing
 *
 * Loaded automatically by PBE_Template_Loader for:
 *   - The 'property' CPT archive  (/property/)
 *   - Any WP Page with the "Property Listing (PBE)" page template selected
 *
 * Theme override: copy to your-theme/pbe-templates/property-listing.php
 *
 * @package Property Booking Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$show_map = PBE_Appearance::get( 'pbe_show_map' );

get_header();
?>

<?php
// ── Build WP_Query for Listings ──
$meta_query = array( 'relation' => 'AND' );

if ( ! empty( $_GET['pbe_beds'] ) ) {
    $meta_query[] = array( 'key' => 'bedrooms', 'value' => intval( $_GET['pbe_beds'] ), 'type' => 'NUMERIC', 'compare' => '>=' );
}
if ( ! empty( $_GET['pbe_baths'] ) ) {
    $meta_query[] = array( 'key' => 'bathrooms', 'value' => floatval( $_GET['pbe_baths'] ), 'type' => 'NUMERIC', 'compare' => '>=' );
}
$guests_val = ! empty( $_GET['pbe_guests'] ) ? intval( $_GET['pbe_guests'] ) : ( ! empty( $_GET['guests'] ) ? intval( $_GET['guests'] ) : 0 );
if ( $guests_val > 0 ) {
    $meta_query[] = array( 'key' => 'max_guests', 'value' => $guests_val, 'type' => 'NUMERIC', 'compare' => '>=' );
}
if ( ! empty( $_GET['pbe_prop_type'] ) ) {
    $meta_query[] = array( 'key' => 'property_type', 'value' => sanitize_text_field( $_GET['pbe_prop_type'] ), 'compare' => '=' );
}

// Search by name / location
$search_args = array();
if ( ! empty( $_GET['p_name'] ) ) {
    $search_args['s'] = sanitize_text_field( $_GET['p_name'] );
    $search_args['pbe_search_title_only'] = true;
}

$paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;
$posts_per_page = intval( PBE_Appearance::get( 'pbe_posts_per_page' ) );

$tax_query = array( 'relation' => 'AND' );
if ( ! empty( $_GET['pbe_tag'] ) ) {
    $tax_query[] = array(
        'taxonomy' => 'property_tag',
        'field'    => 'slug',
        'terms'    => sanitize_title( $_GET['pbe_tag'] ),
    );
}

// Features Filter (Multiple Checkbox Support)
if ( ! empty( $_GET['pbe_features'] ) ) {
    $selected_features = (array) $_GET['pbe_features'];
    foreach ( $selected_features as $f_slug ) {
        $keywords = PBE_Filter_Helper::get_keywords_for_feature( $f_slug );
        if ( ! empty( $keywords ) ) {
            $tax_query[] = array(
                'taxonomy' => 'amenity',
                'field'    => 'name',
                'terms'    => $keywords,
                'operator' => 'IN', // Matches any synonym for this specific feature
            );
        }
    }
}

// Only show active platform properties
$meta_query[] = array(
    'key'     => 'platform_source',
    'value'   => get_option('pbe_active_platform', 'guesty'),
    'compare' => '='
);

// Only show active properties
$meta_query[] = array(
    'key'     => 'is_active',
    'value'   => '1',
    'compare' => '='
);

$unavailable_wp_ids = array();
if ( ! empty( $_GET['checkin'] ) && ! empty( $_GET['checkout'] ) ) {
    global $wpdb;
    $checkin  = sanitize_text_field( $_GET['checkin'] );
    $checkout = sanitize_text_field( $_GET['checkout'] );

    // Calculate stay length
    $checkin_dt  = new DateTime($checkin);
    $checkout_dt = new DateTime($checkout);
    $stay_length = max(1, $checkin_dt->diff($checkout_dt)->days);

    // Find conflicting platform_property_ids based on status OR minNights rules
    $table_name = $wpdb->prefix . 'pbe_calendar_dates';
    $conflicting_platform_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT platform_property_id FROM $table_name 
         WHERE calendar_date >= %s AND calendar_date < %s 
         AND (status != 'available' OR min_nights > %d)",
        $checkin,
        $checkout,
        $stay_length
    ) );

    if ( ! empty( $conflicting_platform_ids ) ) {
        // Prepare list for IN clause
        $in_placeholders = implode(',', array_fill(0, count($conflicting_platform_ids), '%s'));
        
        // Map platform IDs to WordPress Post IDs
        $unavailable_wp_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'platform_property_id' AND meta_value IN ($in_placeholders)",
            ...$conflicting_platform_ids
        ) );
    }
}

$query_args = array_merge( $search_args, array(
    'post_type'      => 'property',
    'posts_per_page' => $posts_per_page,
    'paged'          => $paged,
    'post_status'    => 'publish',
    'meta_query'     => $meta_query,
    'tax_query'      => $tax_query,
    'post__not_in'   => $unavailable_wp_ids,
) );

$pbe_query = new WP_Query( $query_args );

// Separate query for maps to fetch all active matching properties (ignoring pagination!)
$map_data = array();
if ( $show_map ) {
    $map_query_args = $query_args;
    $map_query_args['posts_per_page'] = -1;
    $map_query_args['fields'] = 'ids'; 
    $pbe_map_query = new WP_Query( $map_query_args );

    if ( ! empty( $pbe_map_query->posts ) ) {
        // FAST SCALABILITY FIX: Bulk pre-load all meta and post objects into memory.
        // This reduces 4,500+ individual SQL queries down to exactly 2 queries!
        update_meta_cache( 'post', $pbe_map_query->posts );
        _prime_post_caches( $pbe_map_query->posts, false, false );

        foreach ( $pbe_map_query->posts as $map_pid ) {
            $lat = get_post_meta( $map_pid, 'latitude', true );
            $lng = get_post_meta( $map_pid, 'longitude', true );
            if ( $lat && $lng ) {
                $price   = get_post_meta( $map_pid, 'price_per_night', true );
                $img_url = get_post_meta( $map_pid, 'featured_image_url', true ) ?: PBE_PLUGIN_URL . 'assets/images/placeholder.svg';
                $map_data[] = array(
                    'lat'     => (float) $lat,
                    'lng'     => (float) $lng,
                    'title'   => get_the_title( $map_pid ),
                    'price'   => $price ? '$' . number_format( (float) $price ) : '',
                    'url'     => get_permalink( $map_pid ),
                    'img'     => $img_url,
                    'beds'    => get_post_meta( $map_pid, 'bedrooms', true ),
                    'baths'   => get_post_meta( $map_pid, 'bathrooms', true ),
                    'guests'  => get_post_meta( $map_pid, 'max_guests', true ),
                    'address' => get_post_meta( $map_pid, 'full_address', true ),
                );
            }
        }
    }
}
?>

<div class="pbe-listing-page">

    <!-- ── 1. Hero Section with Search Overlay ── -->
    <?php 
    $hero_img = get_post_meta( get_the_ID(), '_pbe_listing_hero_image', true );
    if ( ! $hero_img ) {
        $hero_img = 'https://images.unsplash.com/photo-1570129477492-45c003edd2be?auto=format&fit=crop&q=80&w=2070';
    }
    ?>
    <div class="pbe-listing-hero" style="background-image: url('<?php echo esc_url( $hero_img ); ?>');">
        <div class="pbe-listing-hero-overlay"></div>
        <div class="pbe-listing-hero-content">
            <h1 class="pbe-hero-title"><?php the_title(); ?></h1>
            <div class="pbe-hero-search-wrap">
                <?php echo do_shortcode( '[property_search]' ); ?>
            </div>
        </div>
    </div>

    <!-- ── 2. Professional Results & Filters Bar ── -->
    <div class="pbe-container">
        


        <div class="pbe-listing-results-bar pbe-results-bar-new">
            <div class="pbe-results-left">
                <div class="pbe-results-count">
                    <?php 
                    $count = $pbe_query->found_posts;
                    $location_text = isset($_GET['pbe_tag']) && $_GET['pbe_tag'] !== '' ? ' in ' . ucwords(str_replace('-', ' ', $_GET['pbe_tag'])) : '';
                    printf( '<span class="pbe-showing-prefix">Showing <strong class="pbe-showing-count">%1$s properties</strong></span><span class="pbe-showing-suffix">%2$s</span>', number_format_i18n( $count ), esc_html($location_text) );
                    ?>
                </div>
            </div>
            <div class="pbe-results-right">
                <button type="button" class="pbe-open-filter-drawer-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
                    Filters
                </button>
            </div>
        </div>

        <?php if ( ! empty($_GET['pbe_beds']) || ! empty($_GET['pbe_baths']) || ! empty($_GET['pbe_guests']) || ! empty($_GET['pbe_prop_type']) || ! empty($_GET['pbe_features']) ) : ?>
        <!-- Active Filters Strip (Moved below top bar) -->
        <div class="pbe-active-filters-list">
            <?php
            // Display removable shortcut pills for all active filters
            if ( ! empty($_GET['pbe_beds']) ) {
                echo '<a href="' . esc_url(remove_query_arg('pbe_beds')) . '" class="pbe-filter-pill">Beds: ' . esc_html($_GET['pbe_beds']) . '<span aria-hidden="true">&times;</span></a>';
            }
            if ( ! empty($_GET['pbe_baths']) ) {
                echo '<a href="' . esc_url(remove_query_arg('pbe_baths')) . '" class="pbe-filter-pill">Baths: ' . esc_html($_GET['pbe_baths']) . '<span aria-hidden="true">&times;</span></a>';
            }
            if ( ! empty($_GET['pbe_guests']) ) {
                echo '<a href="' . esc_url(remove_query_arg('pbe_guests')) . '" class="pbe-filter-pill">Sleeps: ' . esc_html($_GET['pbe_guests']) . '<span aria-hidden="true">&times;</span></a>';
            }
            if ( ! empty($_GET['pbe_prop_type']) ) {
                echo '<a href="' . esc_url(remove_query_arg('pbe_prop_type')) . '" class="pbe-filter-pill">Type: ' . esc_html(ucfirst($_GET['pbe_prop_type'])) . '<span aria-hidden="true">&times;</span></a>';
            }

            // Features Pills (Fixed logic for array-based query args)
            if ( ! empty($_GET['pbe_features']) && is_array($_GET['pbe_features']) ) {
                $mappings = PBE_Filter_Helper::get_feature_mappings();
                $current_features = $_GET['pbe_features'];
                foreach ( $current_features as $f_slug ) {
                    if ( isset( $mappings[ $f_slug ] ) ) {
                        // Create a URL where ONLY this specific feature is removed from the array
                        $remaining = array_diff( $current_features, array($f_slug) );
                        
                        // We must reconstruct the URL properly for arrays
                        $current_url = remove_query_arg( 'pbe_features' );
                        if ( ! empty( $remaining ) ) {
                            $new_url = add_query_arg( array('pbe_features' => $remaining), $current_url );
                        } else {
                            $new_url = $current_url;
                        }
                        
                        echo '<a href="' . esc_url($new_url) . '" class="pbe-filter-pill">' . esc_html($mappings[$f_slug]['label']) . '<span aria-hidden="true">&times;</span></a>';
                    }
                }
            }
            
            // Show 'Clear All' if ANY filter is active (excluding location tag because we keep it in text)
            echo '<a href="' . esc_url( remove_query_arg( array( 'pbe_beds', 'pbe_baths', 'pbe_guests', 'pbe_prop_type', 'pbe_tag', 'pbe_features' ) ) ) . '" class="pbe-clear-all-pill">Clear All</a>';
            ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── 3. Main Split Content ── -->
    <div class="pbe-container">
        <div class="pbe-listing-split-view">
            
            <!-- List Column -->
            <div class="pbe-listing-col-list">
                <div class="pbe-list-inner">
                    
                    <div class="pbe-property-grid" id="pbe-property-results">
                        <?php if ( $pbe_query->have_posts() ) : ?>
                            <?php while ( $pbe_query->have_posts() ) : $pbe_query->the_post();
                                $pid      = get_the_ID();
                                $price    = get_post_meta( $pid, 'price_per_night', true );
                                $bedrooms = get_post_meta( $pid, 'bedrooms',        true );
                                $bathrooms= get_post_meta( $pid, 'bathrooms',       true );
                                $guests   = get_post_meta( $pid, 'max_guests',      true );
                                $lat      = get_post_meta( $pid, 'latitude',        true );
                                $lng      = get_post_meta( $pid, 'longitude',       true );
                                $address  = get_post_meta( $pid, 'full_address',    true );
                                $img_url  = get_post_meta( $pid, 'featured_image_url', true );
                                if ( empty( $img_url ) || strpos( $img_url, 'data:image' ) !== false ) {
                                    $img_url = PBE_PLUGIN_URL . 'assets/images/placeholder.svg';
                                }
                            ?>
                            <?php 
                                // Capture search context to pass to the property page
                                $search_params = array();
                                if ( ! empty( $_GET['checkin'] ) )  $search_params['checkin']  = sanitize_text_field( $_GET['checkin'] );
                                if ( ! empty( $_GET['checkout'] ) ) $search_params['checkout'] = sanitize_text_field( $_GET['checkout'] );
                                if ( ! empty( $_GET['guests'] ) )   $search_params['guests']   = intval( $_GET['guests'] );
                                
                                $book_link = add_query_arg( $search_params, get_permalink() );

                                // Gallery for slider
                                $gallery_json = get_post_meta( $pid, 'property_gallery_urls', true );
                                $gallery      = ! empty( $gallery_json ) ? json_decode( $gallery_json, true ) : array();

                                // Filter out common placeholders using global helper
                                $gallery = array_filter( (array) $gallery, 'pbe_is_valid_image_url' );

                                // Check featured image as well
                                if ( ! pbe_is_valid_image_url( $img_url ) ) {
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
                            <div class="pbe-property-card" data-lat="<?php echo esc_attr( $lat ); ?>" data-lng="<?php echo esc_attr( $lng ); ?>" data-post-id="<?php echo esc_attr( $pid ); ?>">
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
                                        <span title="Bedrooms"><?php echo PBE_Template_Loader::get_svg('bed'); ?> <?php echo esc_html( $bedrooms ?: '0' ); ?> Beds</span>
                                        <span title="Bathrooms"><?php echo PBE_Template_Loader::get_svg('bath'); ?> <?php echo esc_html( $bathrooms ?: '0' ); ?> Baths</span>
                                        <span title="Max Guests"><?php echo PBE_Template_Loader::get_svg('guests'); ?> <?php echo esc_html( $guests ?: '0' ); ?> Guests</span>
                                    </div>
                                    <div class="pbe-property-card-footer">
                                        <a href="<?php echo esc_url( $book_link ); ?>" class="pbe-view-btn">Book Now</a>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; wp_reset_postdata(); ?>
                        <?php else : ?>
                            <div class="pbe-no-results">
                                <div class="pbe-no-results-icon">
                                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><path d="M8.5 11.5L13.5 11.5"></path></svg>
                                </div>
                                <h2 class="pbe-no-results-title">No properties found</h2>
                                <p class="pbe-no-results-text">We couldn't find any properties matching your current search. <br>Try adjusting your chosen dates or clearing your filters.</p>
                                <a href="<?php echo esc_url( remove_query_arg( array( 'pbe_beds', 'pbe_baths', 'pbe_guests', 'pbe_prop_type', 'pbe_tag' ) ) ); ?>" class="pbe-btn-clear-empty">Clear All Filters</a>
                            </div>
                        <?php endif; ?>
                    </div>
 
                    <!-- Pagination -->
                    <?php if ( $pbe_query->max_num_pages > 1 ) : ?>
                    <div class="pbe-pagination">
                        <?php
                        echo paginate_links( array(
                            'base'      => str_replace( 999999999, '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
                            'format'    => '?paged=%#%',
                            'current'   => max( 1, $paged ),
                            'total'     => $pbe_query->max_num_pages,
                            'prev_text' => '&larr; PREV',
                            'next_text' => 'NEXT &rarr;',
                            'type'      => 'list',
                        ) );
                        ?>
                    </div>
                    <?php endif; ?>
 
                </div>
            </div>
 
            <!-- Map Column -->
            <?php if ( $show_map && $pbe_query->have_posts() ) : ?>
            <div class="pbe-listing-col-map">
                <div id="pbe-property-map"></div>
            </div>
            <?php endif; ?>
 
        </div> <!-- /.pbe-listing-split-view -->
 
        <!-- Mobile Floating Toggle Button -->
        <?php if ( $show_map && $pbe_query->have_posts() ) : ?>
        <button type="button" id="pbe-mobile-map-toggle" class="pbe-floating-map-btn">
            <span class="pbe-btn-show-map">Show Map</span>
            <span class="pbe-btn-show-list">Show List <?php echo PBE_Template_Loader::get_svg('list'); ?></span>
        </button>
        <?php endif; ?>
 
    </div> <!-- /.pbe-container -->

</div> <!-- /.pbe-listing-page -->

<?php if ( $show_map && ! empty( $map_data ) ) : ?>
<script>
/* Map data passed to the map initializer */
window.pbeMapData = <?php echo wp_json_encode( $map_data ); ?>;
</script>
<?php endif; ?>

    <!-- ── Filter Drawer overlay and panel ── -->
    <div class="pbe-filter-drawer-overlay"></div>
    <div class="pbe-filter-drawer" id="pbeFilterDrawer">
        <div class="pbe-filter-drawer-header">
            <div class="pbe-fd-title-group">
                <h2>Filters</h2>
            </div>
            <button type="button" class="pbe-filter-close-btn">&times;</button>
        </div>
        
        <form id="pbe-drawer-form" method="GET" action="<?php echo esc_url( get_permalink() ); ?>" style="display: flex; flex-direction: column; flex: 1; overflow: hidden;">
            <?php if ( ! empty($_GET['checkin']) ) : ?><input type="hidden" name="checkin" value="<?php echo esc_attr($_GET['checkin']); ?>"><?php endif; ?>
            <?php if ( ! empty($_GET['checkout']) ) : ?><input type="hidden" name="checkout" value="<?php echo esc_attr($_GET['checkout']); ?>"><?php endif; ?>
            <?php if ( ! empty($_GET['guests']) ) : ?><input type="hidden" name="guests" value="<?php echo esc_attr($_GET['guests']); ?>"><?php endif; ?>
            <?php if ( ! empty($_GET['p_name']) ) : ?><input type="hidden" name="p_name" value="<?php echo esc_attr($_GET['p_name']); ?>"><?php endif; ?>
            <div class="pbe-filter-drawer-body">
                
                <style>
                .pbe-fd-section label { 
                    display: block; 
                    font-weight: 700; 
                    font-size: 0.75rem; 
                    text-transform: uppercase; 
                    letter-spacing: 0.05em; 
                    margin-bottom: 10px; 
                    color: #64748b; 
                }

                /* Custom Select Styling */
                .pbe-custom-select {
                    position: relative;
                    user-select: none;
                }
                .pbe-custom-select-trigger {
                    width: 100%;
                    padding: 12px 16px;
                    border: 1px solid #e2e8f0;
                    border-radius: 12px;
                    font-size: 0.95rem;
                    font-weight: 500;
                    color: #1e293b;
                    background: #fff;
                    cursor: pointer;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    transition: all 0.2s ease;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
                }
                .pbe-custom-select-trigger:hover {
                    border-color: #cbd5e1;
                }
                .pbe-custom-select.is-open .pbe-custom-select-trigger {
                    border-color: var(--pbe-primary, #2563eb);
                    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08);
                }
                .pbe-custom-select-trigger svg {
                    width: 10px;
                    height: 10px;
                    color: #64748b;
                    transition: transform 0.2s ease;
                }
                .pbe-custom-select.is-open .pbe-custom-select-trigger svg {
                    transform: rotate(180deg);
                }

                .pbe-custom-options {
                    position: absolute;
                    top: calc(100% + 8px);
                    left: 0;
                    right: 0;
                    background: #fff;
                    border: 1px solid #e2e8f0;
                    border-radius: 12px;
                    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
                    z-index: 100;
                    padding: 6px;
                    opacity: 0;
                    visibility: hidden;
                    transform: translateY(-10px);
                    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
                }
                .pbe-custom-select.is-open .pbe-custom-options {
                    opacity: 1;
                    visibility: visible;
                    transform: translateY(0);
                }

                .pbe-custom-option {
                    padding: 10px 12px;
                    font-size: 0.9rem;
                    color: #475569;
                    border-radius: 8px;
                    cursor: pointer;
                    transition: all 0.15s ease;
                }
                .pbe-custom-option:hover {
                    background: #f1f5f9;
                    color: #1e293b;
                }
                .pbe-custom-option.selected {
                    background: #f8fafc;
                    color: var(--pbe-primary, #2563eb);
                    font-weight: 600;
                }
                </style>
                
                <!-- Property Type (Custom Dropdown) -->
                <div class="pbe-fd-section">
                    <label>Property Type</label>
                    <div class="pbe-custom-select" id="pbe-prop-type-select">
                        <div class="pbe-custom-select-trigger">
                            <span class="pbe-selected-text">Any Type</span>
                            <svg width="12" height="8" viewBox="0 0 12 8" fill="none"><path d="M1 1.5L6 6.5L11 1.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                        <div class="pbe-custom-options">
                            <div class="pbe-custom-option selected" data-value="">Any Type</div>
                            <?php
                            global $wpdb;
                            $active_platform = get_option('pbe_active_platform', 'guesty');
                            $types = $wpdb->get_col( $wpdb->prepare( "
                                SELECT DISTINCT pm1.meta_value 
                                FROM {$wpdb->postmeta} pm1
                                INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
                                WHERE pm1.meta_key = 'property_type' 
                                AND pm1.meta_value != '' 
                                AND pm2.meta_key = 'platform_source'
                                AND pm2.meta_value = %s
                                ORDER BY pm1.meta_value ASC", 
                                $active_platform 
                            ) );
                            foreach ( $types as $pt ) {
                                $is_selected = ( isset( $_GET['pbe_prop_type'] ) && $_GET['pbe_prop_type'] === $pt ) ? 'selected' : '';
                                echo '<div class="pbe-custom-option ' . $is_selected . '" data-value="' . esc_attr( $pt ) . '">' . esc_html( ucfirst( $pt ) ) . '</div>';
                            }
                            ?>
                        </div>
                        <input type="hidden" name="pbe_prop_type" id="pbe_prop_type_hidden" value="<?php echo esc_attr($_GET['pbe_prop_type'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Bedrooms -->
                <div class="pbe-fd-section">
                    <label>Bedrooms</label>
                    <div class="pbe-fd-toggle-group">
                        <input type="hidden" name="pbe_beds" id="fd_pbe_beds" value="<?php echo esc_attr( $_GET['pbe_beds'] ?? '' ); ?>">
                        <?php $cur_beds = $_GET['pbe_beds'] ?? ''; ?>
                        <button type="button" class="pbe-fd-toggle-btn <?php echo ((string)$cur_beds === '') ? 'active' : ''; ?>" data-target="fd_pbe_beds" data-val="">Any</button>
                        <?php 
                        foreach([1, 2, 3, '4'] as $val): 
                            $raw_val = intval($val);
                            $disp_val = ($val === '4') ? '4+' : $val;
                            $is_active = ((string)$cur_beds === (string)$raw_val) ? 'active' : '';
                        ?>
                            <button type="button" class="pbe-fd-toggle-btn <?php echo $is_active; ?>" data-target="fd_pbe_beds" data-val="<?php echo $raw_val; ?>"><?php echo $disp_val; ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Bathrooms -->
                <div class="pbe-fd-section">
                    <label>Bathrooms</label>
                    <div class="pbe-fd-toggle-group">
                        <input type="hidden" name="pbe_baths" id="fd_pbe_baths" value="<?php echo esc_attr( $_GET['pbe_baths'] ?? '' ); ?>">
                        <?php $cur_baths = $_GET['pbe_baths'] ?? ''; ?>
                        <button type="button" class="pbe-fd-toggle-btn <?php echo ((string)$cur_baths === '') ? 'active' : ''; ?>" data-target="fd_pbe_baths" data-val="">Any</button>
                        <?php 
                        foreach([1, 2, '3'] as $val): 
                            $raw_val = intval($val);
                            $disp_val = ($val === '3') ? '3+' : $val;
                            $is_active = ((string)$cur_baths === (string)$raw_val) ? 'active' : '';
                        ?>
                            <button type="button" class="pbe-fd-toggle-btn <?php echo $is_active; ?>" data-target="fd_pbe_baths" data-val="<?php echo $raw_val; ?>"><?php echo $disp_val; ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Sleeps -->
                <div class="pbe-fd-section">
                    <label>Sleeps</label>
                    <div class="pbe-fd-toggle-group">
                        <input type="hidden" name="pbe_guests" id="fd_pbe_guests" value="<?php echo esc_attr( $_GET['pbe_guests'] ?? '' ); ?>">
                        <?php $cur_guests = $_GET['pbe_guests'] ?? ''; ?>
                        <button type="button" class="pbe-fd-toggle-btn <?php echo ((string)$cur_guests === '') ? 'active' : ''; ?>" data-target="fd_pbe_guests" data-val="">Any</button>
                        <?php 
                        foreach([2, 4, 6, 8, '10'] as $val): 
                            $raw_val = intval($val);
                            $disp_val = ($val === '10') ? '10+' : $val;
                            $is_active = ((string)$cur_guests === (string)$raw_val) ? 'active' : '';
                        ?>
                            <button type="button" class="pbe-fd-toggle-btn <?php echo $is_active; ?>" data-target="fd_pbe_guests" data-val="<?php echo $raw_val; ?>"><?php echo $disp_val; ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Features (Checkboxes) -->
                <div class="pbe-fd-section">
                    <label style="margin-bottom: 5px;">Features</label>
                    <div class="pbe-fd-checkbox-list">
                        <?php 
                        $all_features = PBE_Filter_Helper::get_feature_mappings();
                        $current_features = (array) ($_GET['pbe_features'] ?? []);
                        foreach ( $all_features as $slug => $data ) : 
                            $is_checked = in_array( $slug, $current_features ) ? 'checked' : '';
                        ?>
                            <label class="pbe-fd-checkbox-item">
                                <input type="checkbox" name="pbe_features[]" value="<?php echo esc_attr( $slug ); ?>" <?php echo $is_checked; ?>>
                                <span><?php echo esc_html( $data['label'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                </div> <!-- /.pbe-filter-drawer-body -->

                <div class="pbe-fd-footer">
                    <button type="submit" class="pbe-fd-apply-btn">Apply Filters</button>
                    <!-- Also hidden Location field just to preserve it if it was passed via header search -->
                    <?php if (isset($_GET['pbe_tag']) && $_GET['pbe_tag'] !== ''): ?>
                        <input type="hidden" name="pbe_tag" value="<?php echo esc_attr($_GET['pbe_tag']); ?>">
                    <?php endif; ?>
                </div>
            </form>
        <!-- Drawer End -->
    </div>



<script>
document.addEventListener('DOMContentLoaded', function() {
    // Drawer Toggles
    const drawer = document.getElementById('pbeFilterDrawer');
    const overlay = document.querySelector('.pbe-filter-drawer-overlay');
    const openBtn = document.querySelector('.pbe-open-filter-drawer-btn');
    const closeBtn = document.querySelector('.pbe-filter-close-btn');
    const mapToggleBtn = document.getElementById('pbe-mobile-map-toggle');

    function openDrawer() {
        if(drawer) drawer.classList.add('active');
        if(overlay) overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        if(mapToggleBtn) mapToggleBtn.style.display = 'none';
        document.body.classList.add('pbe-drawer-open');
    }

    function closeDrawer() {
        if(drawer) drawer.classList.remove('active');
        if(overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
        if(mapToggleBtn) mapToggleBtn.style.display = '';
        document.body.classList.remove('pbe-drawer-open');
    }

    if(openBtn) openBtn.addEventListener('click', openDrawer);
    if(closeBtn) closeBtn.addEventListener('click', closeDrawer);
    if(overlay) overlay.addEventListener('click', closeDrawer);

    // Custom Select Logic
    const customSelect = document.getElementById('pbe-prop-type-select');
    if (customSelect) {
        const trigger = customSelect.querySelector('.pbe-custom-select-trigger');
        const options = customSelect.querySelectorAll('.pbe-custom-option');
        const hiddenInput = document.getElementById('pbe_prop_type_hidden');
        const selectedText = customSelect.querySelector('.pbe-selected-text');

        // Initial setup if value exists
        if (hiddenInput.value) {
            const initialOption = customSelect.querySelector(`[data-value="${hiddenInput.value}"]`);
            if (initialOption) {
                selectedText.textContent = initialOption.textContent;
                options.forEach(opt => opt.classList.remove('selected'));
                initialOption.classList.add('selected');
            }
        }

        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            customSelect.classList.toggle('is-open');
        });

        options.forEach(option => {
            option.addEventListener('click', function() {
                const val = this.getAttribute('data-value');
                const label = this.textContent;

                // Update UI
                selectedText.textContent = label;
                options.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');

                // Update Hidden Input
                hiddenInput.value = val;

                // Close
                customSelect.classList.remove('is-open');
            });
        });

        // Close on outside click
        document.addEventListener('click', function() {
            customSelect.classList.remove('is-open');
        });
    }

    // Toggle Button Logic
    const toggleBtns = document.querySelectorAll('.pbe-fd-toggle-btn');
    toggleBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const val = this.getAttribute('data-val');
            const targetInput = document.getElementById(targetId);
            
            // If already active, deselect
            if (this.classList.contains('active')) {
                this.classList.remove('active');
                targetInput.value = '';
            } else {
                // Remove active from siblings
                const siblings = this.parentElement.querySelectorAll('.pbe-fd-toggle-btn');
                siblings.forEach(s => s.classList.remove('active'));
                
                // Set active and value
                this.classList.add('active');
                targetInput.value = val;
            }
        });
    });
});
</script>

<?php get_footer(); ?>
