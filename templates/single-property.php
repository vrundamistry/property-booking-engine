<?php
/**
 * Plugin Template: Single Property
 *
 * Loaded automatically by PBE_Template_Loader for is_singular('property').
 *
 * Theme override: copy to your-theme/pbe-templates/single-property.php
 *
 * @package Property Booking Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

while ( have_posts() ) :
    the_post();

    $pid         = get_the_ID();
    $price       = get_post_meta( $pid, 'price_per_night',     true );
    $bedrooms    = get_post_meta( $pid, 'bedrooms',            true );
    $bathrooms   = get_post_meta( $pid, 'bathrooms',           true );
    $guests      = get_post_meta( $pid, 'max_guests',          true );
    $lat         = get_post_meta( $pid, 'latitude',            true );
    $lng         = get_post_meta( $pid, 'longitude',           true );
    $address     = get_post_meta( $pid, 'full_address',        true );
    $street      = get_post_meta( $pid, 'street',              true );
    $city        = get_post_meta( $pid, 'city',                true );
    $state       = get_post_meta( $pid, 'state',               true );
    
    if ( empty( $address ) ) {
        $address_parts = array_filter( array( $street, $city, $state ) );
        $address = implode( ', ', $address_parts );
    }
    $prop_type   = get_post_meta( $pid, 'property_type',       true );
    $sqft        = get_post_meta( get_the_ID(), 'area_square_feet', true );
    $area_size   = get_post_meta( get_the_ID(), 'area_size', true );
    $area_unit   = get_post_meta( get_the_ID(), 'area_size_unit', true );
    $house_rules = get_post_meta( get_the_ID(), 'house_rules', true );
    $feat_img    = get_post_meta( $pid, 'featured_image_url',  true );
    $platform    = get_post_meta( $pid, 'platform_source',     true );
    $gallery_raw = get_post_meta( $pid, 'property_gallery_urls', true );

    // Build gallery array
    $gallery = array();
    if ( $gallery_raw ) {
        $decoded = json_decode( $gallery_raw, true );
        if ( is_array( $decoded ) ) {
            $gallery = $decoded;
        }
    }
    // Featured image fallback
    if ( empty( $gallery ) && $feat_img ) {
        $gallery = array( $feat_img );
    }
    if ( empty( $gallery ) && has_post_thumbnail() ) {
        $gallery = array( get_the_post_thumbnail_url( $pid, 'large' ) );
    }

    // Flag for real image availability
    $has_real_imgs = ! empty( $gallery );

    // Filter out common Guesty placeholders from gallery
    $gallery = array_filter( (array) $gallery, function($url) {
        return ( strpos($url, 'njmfgob91z7fiilhslkz.jpg') === false && ! empty( $url ) );
    });

    // Re-check after filtering
    $has_real_imgs = ! empty( $gallery );

    if ( ! $has_real_imgs ) {
        $gallery = array( PBE_PLUGIN_URL . 'assets/images/coming-soon.png' );
    }

    // Build grouped amenities array from taxonomy
    $amenity_terms = get_the_terms( $pid, 'amenity' );
    $grouped_amenities = array();
    $ungrouped_amenities = array();

    if ( ! empty( $amenity_terms ) && ! is_wp_error( $amenity_terms ) ) {
        foreach ( $amenity_terms as $term ) {
            if ( $term->parent ) {
                $parent_term = get_term( $term->parent, 'amenity' );
                $parent_name = $parent_term && ! is_wp_error( $parent_term ) ? $parent_term->name : 'Other';
                $grouped_amenities[ $parent_name ][] = $term->name;
            } else {
                // Determine if this term is a parent being assigned, or just an ungrouped amenity
                // We'll class it as ungrouped
                $ungrouped_amenities[] = $term->name;
            }
        }
    }

    // Move 'Others' or 'Other' to the end of the list if it exists
    if ( isset( $grouped_amenities['Others'] ) ) {
        $others = $grouped_amenities['Others'];
        unset( $grouped_amenities['Others'] );
        $grouped_amenities['Others'] = $others;
    } elseif ( isset( $grouped_amenities['Other'] ) ) {
        $other = $grouped_amenities['Other'];
        unset( $grouped_amenities['Other'] );
        $grouped_amenities['Other'] = $other;
    }

    // Process Tags
    $tag_terms = get_the_terms( $pid, 'property_tag' );
    $tags_arr = array();
    if ( ! empty( $tag_terms ) && ! is_wp_error( $tag_terms ) ) {
        foreach ( $tag_terms as $term ) {
            $tags_arr[] = $term->name;
        }
    }

    $price_formatted = $price ? '$' . number_format( (float) $price ) : 'N/A';
    
    // Get Layout Settings
    $container_width = PBE_Appearance::get('pbe_single_property_width');
    $enable_sticky_nav = PBE_Appearance::get('pbe_single_sticky_nav');

?>

<div class="pbe-single-page" style="--pbe-container-width: <?php echo esc_attr($container_width); ?>; 
    --pbe-icon-guests: url('<?php echo PBE_PLUGIN_URL; ?>assets/images/icons/guests.svg');
    --pbe-icon-bed: url('<?php echo PBE_PLUGIN_URL; ?>assets/images/icons/bed.svg');
    --pbe-icon-bath: url('<?php echo PBE_PLUGIN_URL; ?>assets/images/icons/bath.svg');
    --pbe-icon-home: url('<?php echo PBE_PLUGIN_URL; ?>assets/images/icons/home.svg');
    --pbe-icon-sqft: url('<?php echo PBE_PLUGIN_URL; ?>assets/images/icons/sqft.svg');">
    
    <!-- 1. Simplified Slider Gallery Section -->
    <div class="pbe-slider-gallery-container pbe-simple-slider-view <?php echo ! $has_real_imgs ? 'pbe-no-images' : ''; ?>">
        <!-- Main Slider -->
        <div class="swiper pbe-main-slider <?php echo ! $has_real_imgs ? 'pbe-static-hero' : ''; ?>">
            <div class="swiper-wrapper">
                <?php foreach ( $gallery as $url ) : ?>
                    <div class="swiper-slide">
                        <?php if ( $has_real_imgs ) : ?>
                            <a href="<?php echo esc_url( $url ); ?>" class="pbe-glightbox" data-type="image" data-gallery="property-gallery">
                        <?php endif; ?>
                            <img src="<?php echo esc_url( $url ); ?>" alt="<?php the_title_attribute(); ?>" class="pbe-main-slide-img">
                        <?php if ( $has_real_imgs ) : ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ( $has_real_imgs ) : ?>
                <!-- Navigation Arrows (Standard) -->
                <div class="swiper-button-next"></div>
                <div class="swiper-button-prev"></div>
            <?php endif; ?>

            <!-- Simplified Overlay: Title & Location -->
            <div class="pbe-simple-overlay">
                <div class="pbe-overlay-inner">
                    <h1 class="pbe-so-title"><?php the_title(); ?></h1>
                    <div class="pbe-so-location">
                        <img src="<?php echo PBE_PLUGIN_URL; ?>assets/images/icons/location.svg" alt="" class="pbe-so-loc-img">
                        <?php echo esc_html( $address ); ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ( $has_real_imgs ) : ?>
            <!-- Small Professional Thumbnails (Slider) -->
            <div class="pbe-mini-thumbs-wrap">
                <div class="swiper pbe-thumb-slider">
                    <div class="swiper-wrapper">
                        <?php foreach ( $gallery as $index => $url ) : ?>
                            <div class="swiper-slide">
                                <div class="pbe-mini-thumb-item <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                                    <img src="<?php echo esc_url( $url ); ?>" alt="">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ( $enable_sticky_nav === '1' ) : ?>
        <!-- Sticky Navigation Bar -->
        <div class="pbe-sticky-nav-wrapper">
            <div class="pbe-sticky-nav-container">
                <nav class="pbe-sticky-nav">
                    <ul>
                        <?php if ( ! empty( strip_tags( get_the_content() ) ) ) : ?>
                            <li><a href="#pbe-section-overview" class="pbe-nav-link active">Overview</a></li>
                        <?php endif; ?>
                        <?php if ( ! empty( $grouped_amenities ) ) : ?>
                            <li><a href="#pbe-section-amenities" class="pbe-nav-link">Amenities</a></li>
                        <?php endif; ?>
                        <li><a href="#pbe-section-gallery" class="pbe-nav-link">Photos</a></li>
                        <li><a href="#pbe-section-availability" class="pbe-nav-link">Availability</a></li>
                        <li><a href="#pbe-section-location" class="pbe-nav-link">Location</a></li>
                        <li><a href="#pbe-section-reviews" class="pbe-nav-link">Reviews</a></li>
                    </ul>
                </nav>
            </div>
        </div>
    <?php endif; ?>

    <!-- 3. Main Content Split -->
    <div class="pbe-single-container">
        
        <!-- Left Column -->
        <div class="pbe-single-content-left">
            
            <!-- Property Meta Pills -->
            <div class="pbe-prop-meta-pills">
                <?php if ( $prop_type ) : ?>
                <div class="pbe-meta-pill">
                    <div class="pbe-meta-icon pbe-icon-home"></div>
                    <span><?php echo esc_html( $prop_type ); ?></span>
                </div>
                <?php endif; ?>
                <div class="pbe-meta-pill">
                    <div class="pbe-meta-icon pbe-icon-guests"></div>
                    <span><?php echo esc_html( $guests ); ?> Guests</span>
                </div>
                <div class="pbe-meta-pill">
                    <div class="pbe-meta-icon pbe-icon-bed"></div>
                    <span><?php echo esc_html( $bedrooms ); ?> Bedrooms</span>
                </div>
                <div class="pbe-meta-pill">
                    <div class="pbe-meta-icon pbe-icon-bath"></div>
                    <span><?php echo esc_html( $bathrooms ); ?> Baths</span>
                </div>
                <?php if ( $sqft ) : ?>
                <div class="pbe-meta-pill">
                    <div class="pbe-meta-icon pbe-icon-sqft"></div>
                    <span><?php echo esc_html( number_format( (int) $sqft ) ); ?> SQFT</span>
                </div>
                <?php elseif ( $area_size ) : ?>
                <div class="pbe-meta-pill">
                    <div class="pbe-meta-icon pbe-icon-sqft"></div>
                    <span><?php echo esc_html( number_format( (int) $area_size ) ); ?> <?php echo esc_html( $area_unit ? $area_unit : 'SQFT' ); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( strip_tags( get_the_content() ) ) ) : ?>
            <div class="pbe-single-section" id="pbe-section-overview">
                <h2 class="pbe-single-heading">Overview</h2>
                <div class="pbe-description-wrap">
                    <div class="pbe-description-content">
                        <?php the_content(); ?>
                    </div>
                    <button class="pbe-read-more-toggle">Read more</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Amenities Accordion -->
            <?php if ( ! empty( $grouped_amenities ) ) : ?>
            <div class="pbe-amenities-section pbe-single-section" id="pbe-section-amenities">
                <h2 class="pbe-single-heading">Amenities</h2>
                <div class="pbe-amenities-accordion">
                    <?php $i = 0; foreach ( $grouped_amenities as $group_name => $items ) : $i++; ?>
                        <div class="pbe-aa-item">
                            <button class="pbe-aa-trigger">
                                <span><?php echo esc_html( $group_name ); ?></span>
                                <svg class="pbe-aa-icon" viewBox="0 0 24 24" width="20" height="20"><path d="M7 10l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div class="pbe-aa-content">
                                <ul class="pbe-aa-grid">
                                    <?php foreach ( $items as $item ) : ?>
                                        <li><?php echo esc_html( $item ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- House Rules -->
            <?php if ( ! empty( $house_rules ) ) : ?>
            <div class="pbe-house-rules-section pbe-single-section">
                <h2 class="pbe-single-heading">House Rules</h2>
                <div class="pbe-description-wrap">
                    <div class="pbe-description-content pbe-single-text">
                        <?php echo wpautop( wp_kses_post( $house_rules ) ); ?>
                    </div>
                    <button class="pbe-read-more-toggle">Read more</button>
                </div>
            </div>
            <?php endif; ?>



            <!-- Photo Gallery Section -->
            <?php if ( $has_real_imgs ) : ?>
            <div class="pbe-photo-gallery-section pbe-single-section" id="pbe-section-gallery">
                <h2 class="pbe-single-heading">Photo Gallery</h2>
                <div class="pbe-gallery-grid">
                     <?php 
                     $total_imgs = count( $gallery );
                     $idx = 0;
                     foreach ( $gallery as $url ) : 
                        $idx++;
                        $is_visible = ( $idx <= 12 );
                        $is_last_visible = ( $idx === 12 && $total_imgs > 12 );
                        
                        if ( $is_visible ) : 
                     ?>
                        <div class="pbe-gallery-grid-item <?php echo $is_last_visible ? 'view-all' : ''; ?>">
                            <a href="<?php echo esc_url( $url ); ?>" class="pbe-glightbox pbe-gallery-grid-link" data-type="image" data-gallery="gallery-grid" data-index="<?php echo $idx - 1; ?>">
                                <img src="<?php echo esc_url( $url ); ?>" alt="">
                                <?php if ( $is_last_visible ) : ?>
                                    <div class="pbe-gallery-overlay">
                                        <div class="pbe-gallery-plus-icon">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        </div>
                                        <span>View All <?php echo $total_imgs; ?></span>
                                    </div>
                                <?php endif; ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <!-- Hidden images for lightbox -->
                        <a href="<?php echo esc_url( $url ); ?>" class="pbe-glightbox" data-type="image" data-gallery="gallery-grid" data-index="<?php echo $idx - 1; ?>" style="display:none;"></a>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Availability Section -->
            <div class="pbe-availability-section pbe-single-section" id="pbe-section-availability">
                <?php 
                // Reverted back to using the key (without hyphens)
                $property_key_raw = get_post_meta( get_the_ID(), 'propertyId_key', true );
                $property_key = str_replace('-', '', $property_key_raw);
                $calendar_widget_id = get_option('pbe_ownerrez_calendar_widget_id');
                
                if ( $platform === 'ownerrez' && ! empty( $property_key ) && ! empty( $calendar_widget_id ) ) {
                    ?>
                    <h2 class="pbe-single-heading">Availability Calendar</h2>
                    <div class="ownerrez-widget" data-propertyId="<?php echo esc_attr( $property_key ); ?>" data-widget-type="Multiple Month Calendar" data-widgetId="<?php echo esc_attr( $calendar_widget_id ); ?>"></div>
                    <script src="https://app.ownerrez.com/widget.js"></script>
                    <?php
                } else {
                    echo do_shortcode('[pbe_availability rows="2" cols="2" total_months="2" title="Availability Calendar"]'); 
                }
                ?>
            </div>

            <!-- Map -->
            <div class="pbe-map-section pbe-single-section" id="pbe-section-location">
                <div class="pbe-section-header">
                    <div class="pbe-section-title-area">
                        <h2 class="pbe-single-heading">Location</h2>
                        <?php if ( $city || $state ) : ?>
                            <p class="pbe-section-subtitle"><?php echo esc_html( trim( "$city, $state", ', ' ) ); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ( $lat && $lng ) : ?>
                        <div class="pbe-section-actions">
                            <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $lat; ?>,<?php echo $lng; ?>" 
                               target="_blank" rel="noopener" class="pbe-map-directions-btn">
                                <svg class="pbe-map-directions-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                    <circle cx="12" cy="10" r="3"></circle>
                                </svg>
                                Open in Google Maps
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pbe-map-seamless-wrapper">
                    <div id="pbe-single-map" class="pbe-map-wrapper"
                         data-lat="<?php echo esc_attr( $lat ?: '0' ); ?>"
                         data-lng="<?php echo esc_attr( $lng ?: '0' ); ?>">
                    </div>
                </div>
            </div>

        </div><!-- /.pbe-single-content-left -->

        <!-- Right Column (Sidebar) -->
        <div class="pbe-single-sidebar">
            <button type="button" class="pbe-mobile-drawer-close">&times;</button>
            <div class="pbe-sticky-widget">
                <?php 
                $property_key_raw = get_post_meta( get_the_ID(), 'propertyId_key', true );
                $property_key = str_replace('-', '', $property_key_raw);
                $booking_widget_id = get_option('pbe_ownerrez_booking_widget_id');
                
                if ( $platform === 'ownerrez' && ! empty( $property_key ) && ! empty( $booking_widget_id ) ) {
                    ?>
                    <div class="ownerrez-widget" data-propertyId="<?php echo esc_attr( $property_key ); ?>" data-widget-type="Booking/Inquiry" data-widgetId="<?php echo esc_attr( $booking_widget_id ); ?>"></div>
                    <script type="text/javascript" src="https://app.ownerrez.com/widget.js"></script>
                    <?php
                } else {
                    $widget_type = get_option('pbe_default_booking_widget', 'standard');
                    if ( $widget_type === 'native' ) {
                        echo do_shortcode( '[pbe_native_booking_widget id="' . get_the_ID() . '"]' );
                    } else {
                        echo do_shortcode( '[pbe_booking_widget id="' . get_the_ID() . '"]' );
                    }
                }
                ?>
            </div>    
        </div><!-- /.pbe-single-sidebar -->

    </div><!-- /.pbe-single-container -->

    <!-- Availability & Reviews Section -->
    <div class="pbe-reviews-section-full pbe-no-top-padding" id="pbe-section-reviews">
        <div class="pbe-single-container">
            <div class="pbe-reviews-header-flex">
                <h2 class="pbe-single-heading">Reviews</h2>
                <button class="pbe-mobile-review-trigger">Write a Review</button>
            </div>
            
            <?php
            $reviews_query = new WP_Query(array(
                'post_type'      => 'pbe_review',
                'post_status'    => 'publish',
                'post_parent'    => get_the_ID(),
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC'
            ));
            
            $review_count = $reviews_query->found_posts;
            $avg_rating   = 0;
            
            if ($review_count > 0) {
                $total_stars = 0;
                foreach ($reviews_query->posts as $rev) {
                    $rating = (float) get_post_meta($rev->ID, 'pbe_rating', true);
                    $total_stars += ($rating > 0) ? $rating : 5;
                }
                $avg_rating = round($total_stars / $review_count, 2);
            }

            $handler = new PBE_Review_Handler();
            ?>

            <div class="pbe-reviews-layout">
                <!-- Main Content: Reviews List -->
                <div class="pbe-reviews-main">
                    <?php if ($review_count > 0) : ?>
                        <div id="pbe-reviews-target" class="pbe-reviews-grid-wrapper">
                            <div class="pbe-reviews-grid pbe-reviews-list">
                                <?php 
                                $initial_reviews = array_slice($reviews_query->posts, 0, 5);
                                foreach ($initial_reviews as $rev) {
                                    echo $handler->render_review_card_html($rev);
                                }
                                ?>
                            </div>
                            
                            <div class="pbe-reviews-loader" style="display:none;">
                                <div class="pbe-spinner"></div>
                            </div>
                        </div>
                    <?php else : ?>
                        <p>No reviews yet for this property.</p>
                    <?php endif; wp_reset_postdata(); ?>

                    <?php if ($review_count > 5) : 
                        $total_pages = ceil($review_count / 5);
                        ?>
                        <div class="pbe-reviews-pagination">
                            <button class="pbe-pagination-btn prev" disabled data-page="1" data-property="<?php echo get_the_ID(); ?>">Previous</button>
                            
                            <div class="pbe-pagination-numbers">
                                <?php 
                                $current_page = 1;
                                $adjacents = 1;
                                $property_id = get_the_ID();
                                
                                if ($total_pages <= 7) {
                                    for ($p = 1; $p <= $total_pages; $p++) {
                                        echo '<button class="pbe-pagination-num ' . ($p === $current_page ? 'active' : '') . '" data-page="' . $p . '" data-property="' . $property_id . '">' . $p . '</button>';
                                    }
                                } else {
                                    // Always show page 1
                                    echo '<button class="pbe-pagination-num ' . ($current_page === 1 ? 'active' : '') . '" data-page="1" data-property="' . $property_id . '">1</button>';
                                    
                                    // Start dots
                                    echo '<span class="pbe-pagination-dots" style="display:none;">...</span>';
                                    
                                    // Middle pages (Initially just 2 and 3)
                                    for ($p = 2; $p <= 3; $p++) {
                                        echo '<button class="pbe-pagination-num" data-page="' . $p . '" data-property="' . $property_id . '">' . $p . '</button>';
                                    }
                                    
                                    // End dots
                                    echo '<span class="pbe-pagination-dots">...</span>';
                                    
                                    // Always show last page
                                    echo '<button class="pbe-pagination-num" data-page="' . $total_pages . '" data-property="' . $property_id . '">' . $total_pages . '</button>';
                                }
                                ?>
                            </div>

                            <button class="pbe-pagination-btn next" data-page="2" data-property="<?php echo get_the_ID(); ?>">Next</button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar: Sentiment & Form -->
                <div class="pbe-reviews-sidebar">
                    <div class="pbe-review-sidebar-box pbe-sentiment-box">
                        <h3>Guest Sentiment</h3>
                        <div class="pbe-sentiment-score">
                            <span class="pbe-big-score"><?php echo ($review_count > 0) ? number_format($avg_rating, 1) : '0.0'; ?></span>
                            <div class="pbe-sentiment-stars">
                                <div class="pbe-review-rating-stars">
                                    <?php for($i=1; $i<=5; $i++): ?>
                                        <span class="pbe-star <?php echo ($i <= round($avg_rating)) ? 'filled' : ''; ?>">★</span>
                                    <?php endfor; ?>
                                </div>
                                <span>Based on <?php echo $review_count; ?> reviews</span>
                            </div>
                        </div>
                    </div>

                    <div id="pbe-review-form-wrap" class="pbe-review-sidebar-box pbe-review-form-box">
                        <button class="pbe-mobile-review-close">&times;</button>
                        <h3>Share Your Story</h3>
                        <p class="pbe-sidebar-desc">Help other travelers find their next perfect escape.</p>
                        <form id="pbe-submit-review-form">
                            <?php wp_nonce_field('pbe_submit_review', 'pbe_review_nonce'); ?>
                            <input type="hidden" name="property_id" value="<?php echo get_the_ID(); ?>">
                            <input type="hidden" name="platform_source" value="<?php echo esc_attr($platform); ?>">
                            <div class="pbe-form-group">
                                <label class="pbe-label-caps">Your Rating</label>
                                <div class="pbe-star-rating-selector">
                                    <input type="radio" id="star5" name="pbe_rating" value="5" required /><label for="star5">★</label>
                                    <input type="radio" id="star4" name="pbe_rating" value="4" required /><label for="star4">★</label>
                                    <input type="radio" id="star3" name="pbe_rating" value="3" required /><label for="star3">★</label>
                                    <input type="radio" id="star2" name="pbe_rating" value="2" required /><label for="star2">★</label>
                                    <input type="radio" id="star1" name="pbe_rating" value="1" required /><label for="star1">★</label>
                                </div>
                            </div>

                            <div class="pbe-form-group">
                                <label class="pbe-label-caps">Review Title</label>
                                <input type="text" name="review_title" required placeholder="Summarize your stay">
                            </div>

                            <div class="pbe-form-group">
                                <label class="pbe-label-caps">Your Narrative</label>
                                <textarea name="review_text" rows="4" required placeholder="How was your experience?"></textarea>
                            </div>

                            <div class="pbe-form-group">
                                <label class="pbe-label-caps">Your Name</label>
                                <input type="text" name="reviewer_name" required placeholder="Your Name">
                            </div>

                            <div class="pbe-form-group">
                                <label class="pbe-label-caps">Your Email</label>
                                <input type="email" name="reviewer_email" required placeholder="your@email.com">
                            </div>

                            <div id="pbe-review-status"></div>
                            <button type="submit" class="pbe-submit-btn">Post Review</button>
                        </form>
                    </div>
                </div>
            </div>


        </div>
    </div>

</div><!-- /.pbe-single-page -->

<div class="pbe-mobile-booking-drawer-overlay"></div>
<div class="pbe-mobile-sticky-footer">
    <div class="pbe-msf-inner">
        <div class="pbe-msf-price">
            <span class="pbe-msf-amount"><?php echo $price_formatted; ?></span>
            <span class="pbe-msf-label">/ night</span>
        </div>
        <button type="button" class="pbe-msf-btn" id="pbe-mobile-drawer-trigger">Check Availability</button>
    </div>
</div>


<?php endwhile; ?>
<?php get_footer(); ?>

