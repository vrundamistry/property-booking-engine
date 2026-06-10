<?php
/**
 * Custom API Webhook Receiver
 * Exposes endpoints for unsupported PMS integrations (via Zapier, Make, etc.)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Custom_API {

    private $namespace = 'pbe/v1';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register Custom API Endpoints
     */
    public function register_routes() {
        // 1. Property Sync Endpoint
        register_rest_route( $this->namespace, '/properties', array(
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => array( $this, 'handle_property_sync' ),
            'permission_callback' => array( $this, 'authenticate_request' ),
        ) );

        // 2. Calendar / Availability Sync Endpoint
        register_rest_route( $this->namespace, '/availability', array(
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => array( $this, 'handle_availability_sync' ),
            'permission_callback' => array( $this, 'authenticate_request' ),
        ) );

        // 3. Reviews Sync Endpoint
        register_rest_route( $this->namespace, '/reviews', array(
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => array( $this, 'handle_review_sync' ),
            'permission_callback' => array( $this, 'authenticate_request' ),
        ) );
    }

    /**
     * Authenticate incoming webhook requests using a Secret Bearer Key
     */
    public function authenticate_request( $request ) {
        // Retrieve key from request header
        $auth_header = $request->get_header( 'Authorization' );
        $expected_key = get_option( 'pbe_custom_api_secret_key', 'my_default_secret_key_123' );

        if ( empty( $auth_header ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Missing API Key Authorization Header.', 'property-booking-engine' ), array( 'status' => 401 ) );
        }

        // Clean the bearer format: "Bearer YOUR_SECRET_KEY"
        $token = str_replace( 'Bearer ', '', $auth_header );

        if ( hash_equals( $expected_key, $token ) ) {
            return true;
        }

        return new WP_Error( 'rest_forbidden', __( 'Invalid API Key.', 'property-booking-engine' ), array( 'status' => 403 ) );
    }

    /**
     * Handler: Sync Listings / Properties (Supports both single object and bulk array payloads)
     */
    public function handle_property_sync( WP_REST_Request $request ) {
        // Security Check: Only allow sync if Custom API is the active platform in settings
        if ( get_option( 'pbe_active_platform', 'guesty' ) !== 'custom' ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Custom API is not currently active in settings.' ), 400 );
        }

        $params = $request->get_json_params();

        if ( empty( $params ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Empty request payload.' ), 400 );
        }

        // Detect if payload is a single property object or an array of property objects
        $is_batch = ( isset( $params[0] ) && is_array( $params[0] ) );
        $properties = $is_batch ? $params : array( $params );

        $results = array();
        $has_errors = false;

        foreach ( $properties as $property_data ) {
            if ( empty( $property_data['platform_property_id'] ) || empty( $property_data['name'] ) ) {
                $results[] = array(
                    'success' => false,
                    'message' => 'Missing platform_property_id or name for a property.'
                );
                $has_errors = true;
                continue;
            }

            $platform_property_id = sanitize_text_field( $property_data['platform_property_id'] );
            $platform_source      = 'custom';

            // 1. Check if the property already exists in DB
            global $wpdb;
            $post_id = $wpdb->get_var( $wpdb->prepare( "
                SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = 'platform_property_id' 
                AND meta_value = %s 
                LIMIT 1
            ", $platform_property_id ) );

            $post_data = array(
                'post_title'   => sanitize_text_field( $property_data['name'] ),
                'post_content' => wp_kses_post( isset( $property_data['description'] ) ? $property_data['description'] : '' ),
                'post_status'  => 'publish',
                'post_type'    => 'property',
            );

            if ( $post_id ) {
                // Update existing listing
                $post_data['ID'] = $post_id;
                wp_update_post( $post_data );
                $action = 'updated';
            } else {
                // Insert new listing
                $post_id = wp_insert_post( $post_data );
                $action = 'created';
            }

            if ( is_wp_error( $post_id ) ) {
                $results[] = array(
                    'platform_property_id' => $platform_property_id,
                    'success' => false,
                    'message' => $post_id->get_error_message()
                );
                $has_errors = true;
                continue;
            }

            // 2. Save/Update Property Metadata
            update_post_meta( $post_id, 'platform_property_id', $platform_property_id );
            update_post_meta( $post_id, 'platform_source', $platform_source );
            update_post_meta( $post_id, 'price_per_night', isset( $property_data['price'] ) ? floatval( $property_data['price'] ) : 0.0 );
            update_post_meta( $post_id, 'bedrooms', isset( $property_data['bedrooms'] ) ? intval( $property_data['bedrooms'] ) : 0 );
            update_post_meta( $post_id, 'bathrooms', isset( $property_data['bathrooms'] ) ? floatval( $property_data['bathrooms'] ) : 0.0 );
            update_post_meta( $post_id, 'max_guests', isset( $property_data['max_guests'] ) ? intval( $property_data['max_guests'] ) : 1 );
            update_post_meta( $post_id, 'min_nights', isset( $property_data['min_nights'] ) ? intval( $property_data['min_nights'] ) : 1 );
            
            // Extended metadata fields from other PMS systems
            if ( isset( $property_data['max_nights'] ) ) {
                update_post_meta( $post_id, 'max_nights', intval( $property_data['max_nights'] ) );
            }
            if ( isset( $property_data['currency'] ) ) {
                update_post_meta( $post_id, 'currency', sanitize_text_field( $property_data['currency'] ) );
            }
            if ( isset( $property_data['property_type'] ) ) {
                update_post_meta( $post_id, 'property_type', sanitize_text_field( $property_data['property_type'] ) );
            }
            if ( isset( $property_data['room_type'] ) ) {
                update_post_meta( $post_id, 'room_type', sanitize_text_field( $property_data['room_type'] ) );
            }
            if ( isset( $property_data['is_active'] ) ) {
                update_post_meta( $post_id, 'is_active', $property_data['is_active'] ? '1' : '0' );
            }
            if ( isset( $property_data['is_listed'] ) ) {
                update_post_meta( $post_id, 'is_listed', $property_data['is_listed'] ? '1' : '0' );
            }
            if ( isset( $property_data['area_size'] ) ) {
                update_post_meta( $post_id, 'area_square_feet', sanitize_text_field( $property_data['area_size'] ) );
            }
            if ( isset( $property_data['cleaning_fee'] ) ) {
                update_post_meta( $post_id, 'cleaning_fee', floatval( $property_data['cleaning_fee'] ) );
            }
            if ( isset( $property_data['damage_deposit'] ) ) {
                update_post_meta( $post_id, 'damage_deposit', floatval( $property_data['damage_deposit'] ) );
            }
            if ( isset( $property_data['instant_bookable'] ) ) {
                update_post_meta( $post_id, 'instant_bookable', intval( $property_data['instant_bookable'] ) );
            }
            if ( isset( $property_data['check_in_start'] ) ) {
                update_post_meta( $post_id, 'check_in_start', sanitize_text_field( $property_data['check_in_start'] ) );
            }
            if ( isset( $property_data['check_in_end'] ) ) {
                update_post_meta( $post_id, 'check_in_end', sanitize_text_field( $property_data['check_in_end'] ) );
            }
            if ( isset( $property_data['check_out'] ) ) {
                update_post_meta( $post_id, 'check_out', sanitize_text_field( $property_data['check_out'] ) );
            }
            if ( isset( $property_data['house_rules'] ) ) {
                update_post_meta( $post_id, 'house_rules', wp_kses_post( $property_data['house_rules'] ) );
            }
            if ( isset( $property_data['booking_url'] ) ) {
                update_post_meta( $post_id, 'booking_url', esc_url_raw( $property_data['booking_url'] ) );
            }

            // Location mapping
            update_post_meta( $post_id, 'latitude', isset( $property_data['latitude'] ) ? sanitize_text_field( $property_data['latitude'] ) : '' );
            update_post_meta( $post_id, 'longitude', isset( $property_data['longitude'] ) ? sanitize_text_field( $property_data['longitude'] ) : '' );
            update_post_meta( $post_id, 'full_address', isset( $property_data['full_address'] ) ? sanitize_text_field( $property_data['full_address'] ) : '' );
            if ( isset( $property_data['street'] ) ) {
                update_post_meta( $post_id, 'street', sanitize_text_field( $property_data['street'] ) );
            }
            if ( isset( $property_data['city'] ) ) {
                update_post_meta( $post_id, 'city', sanitize_text_field( $property_data['city'] ) );
            }
            if ( isset( $property_data['state'] ) ) {
                update_post_meta( $post_id, 'state', sanitize_text_field( $property_data['state'] ) );
            }
            if ( isset( $property_data['zip'] ) ) {
                update_post_meta( $post_id, 'zip', sanitize_text_field( $property_data['zip'] ) );
            }
            if ( isset( $property_data['country'] ) ) {
                update_post_meta( $post_id, 'country', sanitize_text_field( $property_data['country'] ) );
            }
            if ( isset( $property_data['country_code'] ) ) {
                update_post_meta( $post_id, 'country_code', sanitize_text_field( $property_data['country_code'] ) );
            }

            // 3. Save Gallery Images
            if ( ! empty( $property_data['gallery_urls'] ) && is_array( $property_data['gallery_urls'] ) ) {
                $gallery = array_map( 'esc_url_raw', $property_data['gallery_urls'] );
                update_post_meta( $post_id, 'property_gallery_urls', wp_json_encode( $gallery ) );
                update_post_meta( $post_id, 'featured_image_url', $gallery[0] );
            }

            // 4. Map Amenities Hierarchically
            if ( ! empty( $property_data['amenities'] ) && is_array( $property_data['amenities'] ) ) {
                $term_ids = array();
                
                // Helper to tag terms with platform source so they match filters (recursive to prevent broken hierarchies)
                $tag_term_with_platform = function( $term_id, $platform ) use ( &$tag_term_with_platform ) {
                    $platforms = get_term_meta( $term_id, 'pbe_platform_source', true );
                    if ( empty( $platforms ) ) {
                        $platforms = array();
                    }
                    if ( ! is_array( $platforms ) ) {
                        $platforms = array( $platforms );
                    }
                    if ( ! in_array( $platform, $platforms ) ) {
                        $platforms[] = $platform;
                        update_term_meta( $term_id, 'pbe_platform_source', $platforms );
                    }

                    // Recursive walk up to tag all parent categories
                    $term = get_term( $term_id, 'amenity' );
                    if ( $term && ! is_wp_error( $term ) && $term->parent > 0 ) {
                        $tag_term_with_platform( $term->parent, $platform );
                    }
                };

                foreach ( $property_data['amenities'] as $amenity ) {
                    $name = '';
                    $group = 'Other';

                    if ( is_array( $amenity ) ) {
                        $name  = isset( $amenity['name'] ) ? sanitize_text_field( $amenity['name'] ) : '';
                        $group = ! empty( $amenity['group'] ) ? sanitize_text_field( $amenity['group'] ) : 'Other';
                    } else {
                        $name  = sanitize_text_field( $amenity );
                    }

                    if ( empty( $name ) ) {
                        continue;
                    }

                    $parent_id = 0;
                    if ( ! empty( $group ) ) {
                        // Generate a custom-specific slug for the parent group
                        $group_slug = sanitize_title( $group ) . '-custom';
                        $group_term = term_exists( $group_slug, 'amenity' );
                        if ( ! $group_term ) {
                            $group_term = wp_insert_term( $group, 'amenity', array( 'slug' => $group_slug ) );
                        }
                        if ( ! is_wp_error( $group_term ) ) {
                            $parent_id = is_array( $group_term ) ? $group_term['term_id'] : $group_term;
                            $tag_term_with_platform( $parent_id, $platform_source );
                        }
                    }

                    // Check/Create main amenity (using custom platform specific slug)
                    $slug = sanitize_title( $name ) . '-custom';
                    $term = term_exists( $slug, 'amenity' );
                    if ( ! $term ) {
                        $term = wp_insert_term( $name, 'amenity', array( 'slug' => $slug, 'parent' => $parent_id ) );
                    }
                    $term_id = is_array( $term ) ? $term['term_id'] : $term;
                    if ( $term_id && ! is_wp_error( $term_id ) ) {
                        $term_ids[] = (int) $term_id;
                        $tag_term_with_platform( $term_id, $platform_source );
                    }
                }
                
                if ( ! empty( $term_ids ) ) {
                    wp_set_object_terms( $post_id, $term_ids, 'amenity', false );
                }
            }

            // Update Sync Timestamp
            update_post_meta( $post_id, '_pbe_last_sync_time', time() );

            $results[] = array(
                'platform_property_id' => $platform_property_id,
                'success' => true,
                'message' => "Property Listing was successfully {$action}.",
                'post_id' => $post_id 
            );
        }

        // Clear taxonomy cache to ensure immediate display in WordPress admin
        clean_taxonomy_cache( 'amenity' );

        $status_code = $has_errors ? 207 : 200;
        return new WP_REST_Response( $is_batch ? $results : $results[0], $status_code );
    }

    /**
     * Handler: Sync Availability Calendars
     */
    public function handle_availability_sync( WP_REST_Request $request ) {
        // Security Check: Only allow sync if Custom API is the active platform in settings
        if ( get_option( 'pbe_active_platform', 'guesty' ) !== 'custom' ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Custom API is not currently active in settings.' ), 400 );
        }

        $params = $request->get_json_params();

        if ( empty( $params ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Empty request payload.' ), 400 );
        }

        // Detect if payload is a single property calendar object or an array of property calendar objects
        $is_batch = ( isset( $params[0] ) && is_array( $params[0] ) );
        $calendars = $is_batch ? $params : array( $params );

        global $wpdb;
        $table_name = $wpdb->prefix . 'pbe_calendar_dates';
        $today = wp_date( 'Y-m-d' );
        
        $results = array();
        $has_errors = false;

        foreach ( $calendars as $calendar_data ) {
            if ( empty( $calendar_data['platform_property_id'] ) || empty( $calendar_data['days'] ) || ! is_array( $calendar_data['days'] ) ) {
                $results[] = array(
                    'success' => false,
                    'message' => 'Missing platform_property_id or calendar days array for a record.'
                );
                $has_errors = true;
                continue;
            }

            $platform_property_id = sanitize_text_field( $calendar_data['platform_property_id'] );
            $days = $calendar_data['days'];

            // 1. Clear future dates for this property to avoid obsolete records
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM $table_name WHERE platform_property_id = %s AND calendar_date >= %s",
                $platform_property_id,
                $today
            ) );

            // 2. Prepare bulk insert queries for performance
            $bulk_values = array();
            foreach ( $days as $day ) {
                $status     = isset( $day['status'] ) ? strtolower( $day['status'] ) : 'available';
                $min_nights = isset( $day['min_nights'] ) ? intval( $day['min_nights'] ) : 1;
                $cta        = ! empty( $day['cta'] ) ? 1 : 0;
                $ctd        = ! empty( $day['ctd'] ) ? 1 : 0;
                $price      = isset( $day['price'] ) ? floatval( $day['price'] ) : null;
                $date       = sanitize_text_field( $day['date'] ); // Format: YYYY-MM-DD
                $guests     = isset( $day['guests'] ) ? intval( $day['guests'] ) : 1;

                // SMART SPARSE STORAGE: Skip standard available days to avoid database bloat
                if ( $status === 'available' && $min_nights <= 1 && $cta === 0 && $ctd === 0 && empty( $price ) ) {
                    continue;
                }

                $bulk_values[] = $wpdb->prepare(
                    "(%s, %s, %s, %d, %d, %d, %d, %f)",
                    $platform_property_id, $date, $status, $min_nights, $guests, $cta, $ctd, $price
                );
            }

            // 3. Batch insert new data rows
            $logged_count = 0;
            if ( ! empty( $bulk_values ) ) {
                $query = "INSERT INTO $table_name (platform_property_id, calendar_date, status, min_nights, guests, cta, ctd, price) VALUES " . implode( ', ', $bulk_values );
                $wpdb->query( $query );
                $logged_count = count( $bulk_values );
            }

            $results[] = array(
                'platform_property_id' => $platform_property_id,
                'success' => true,
                'message' => 'Calendar availability sync successful.',
                'total_dates_logged' => $logged_count
            );
        }

        // Update calendar sync time
        update_option( 'pbe_last_calendar_sync_custom', time() );

        $status_code = $has_errors ? 207 : 200;
        return new WP_REST_Response( $is_batch ? $results : $results[0], $status_code );
    }

    /**
     * Handler: Sync Reviews (Supports both single property reviews object and bulk arrays)
     */
    public function handle_review_sync( WP_REST_Request $request ) {
        // Security Check: Only allow sync if Custom API is the active platform in settings
        if ( get_option( 'pbe_active_platform', 'guesty' ) !== 'custom' ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Custom API is not currently active in settings.' ), 400 );
        }

        $params = $request->get_json_params();

        if ( empty( $params ) ) {
            return new WP_REST_Response( array( 
                'success' => false, 
                'message' => 'Empty request payload.',
                'debug'   => array(
                    'body'         => $request->get_body(),
                    'content_type' => $request->get_header( 'Content-Type' ),
                    'method'       => $request->get_method()
                )
            ), 400 );
        }

        $is_batch = ( isset( $params[0] ) && is_array( $params[0] ) );
        $payloads = $is_batch ? $params : array( $params );

        $results = array();
        $has_errors = false;

        foreach ( $payloads as $payload ) {
            if ( empty( $payload['platform_property_id'] ) || ! isset( $payload['reviews'] ) || ! is_array( $payload['reviews'] ) ) {
                $results[] = array(
                    'success' => false,
                    'message' => 'Missing platform_property_id or reviews array.'
                );
                $has_errors = true;
                continue;
            }

            $platform_property_id = sanitize_text_field( $payload['platform_property_id'] );

            // Find property post by platform_property_id
            global $wpdb;
            $post_id = $wpdb->get_var( $wpdb->prepare( "
                SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = 'platform_property_id' 
                AND meta_value = %s 
                LIMIT 1
            ", $platform_property_id ) );

            if ( ! $post_id ) {
                $results[] = array(
                    'platform_property_id' => $platform_property_id,
                    'success' => false,
                    'message' => 'Property not found.'
                );
                $has_errors = true;
                continue;
            }

            $synced_ext_ids = array();
            $reviews_processed = 0;

            foreach ( $payload['reviews'] as $rev ) {
                if ( empty( $rev['external_id'] ) || empty( $rev['author'] ) || ! isset( $rev['rating'] ) ) {
                    continue;
                }

                $ext_id = sanitize_text_field( $rev['external_id'] );
                $synced_ext_ids[] = $ext_id;

                $existing_review = $wpdb->get_var( $wpdb->prepare( "
                    SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = 'pbe_review_id' AND meta_value = %s
                ", $ext_id ) );

                $date_val = ! empty( $rev['date'] ) ? sanitize_text_field( $rev['date'] ) : current_time( 'mysql' );

                $rev_data = array(
                    'post_title'   => sanitize_text_field( $rev['author'] ),
                    'post_content' => wp_kses_post( isset( $rev['text'] ) ? $rev['text'] : '' ),
                    'post_status'  => 'publish',
                    'post_type'    => 'pbe_review',
                    'post_parent'  => $post_id,
                    'post_date'    => date( 'Y-m-d H:i:s', strtotime( $date_val ) )
                );

                if ( $existing_review ) {
                    $rev_data['ID'] = $existing_review;
                    wp_update_post( $rev_data );
                    $review_post_id = $existing_review;
                } else {
                    $review_post_id = wp_insert_post( $rev_data );
                }

                if ( $review_post_id && ! is_wp_error( $review_post_id ) ) {
                    update_post_meta( $review_post_id, 'pbe_review_id', $ext_id );
                    update_post_meta( $review_post_id, 'pbe_rating', floatval( $rev['rating'] ) );
                    update_post_meta( $review_post_id, 'pbe_source', sanitize_text_field( isset( $rev['source'] ) ? $rev['source'] : 'custom' ) );
                    update_post_meta( $review_post_id, 'pbe_platform_source', 'custom' );

                    if ( ! empty( $rev['title'] ) ) {
                        update_post_meta( $review_post_id, 'pbe_review_title', sanitize_text_field( $rev['title'] ) );
                    }
                    if ( ! empty( $rev['reservation_id'] ) ) {
                        update_post_meta( $review_post_id, 'pbe_platform_res_id', sanitize_text_field( $rev['reservation_id'] ) );
                    }
                    if ( ! empty( $rev['listing_site'] ) ) {
                        update_post_meta( $review_post_id, 'pbe_listing_site', sanitize_text_field( $rev['listing_site'] ) );
                    }
                    $reviews_processed++;
                }
            }

            // Cleanup: remove older synced reviews for this property that weren't included in this payload
            $existing_reviews_query = new WP_Query( array(
                'post_type'      => 'pbe_review',
                'post_status'    => 'any',
                'post_parent'    => $post_id,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'     => 'pbe_review_id',
                        'compare' => 'EXISTS'
                    ),
                    array(
                        'key'     => 'pbe_platform_source',
                        'value'   => 'custom'
                    )
                )
            ) );

            if ( ! empty( $existing_reviews_query->posts ) ) {
                foreach ( $existing_reviews_query->posts as $existing_review_id ) {
                    $ext_review_id = get_post_meta( $existing_review_id, 'pbe_review_id', true );
                    if ( ! empty( $ext_review_id ) && ! in_array( $ext_review_id, $synced_ext_ids ) ) {
                        wp_delete_post( $existing_review_id, true );
                    }
                }
            }

            $results[] = array(
                'platform_property_id' => $platform_property_id,
                'success' => true,
                'message' => sprintf( '%d reviews synced successfully.', $reviews_processed )
            );
        }

        $status_code = $has_errors ? 207 : 200;
        return new WP_REST_Response( $is_batch ? $results : $results[0], $status_code );
    }
}

// Instantiate the Custom API Handler
new PBE_Custom_API();
