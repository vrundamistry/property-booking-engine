<?php
/**
 * Guesty Platform Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Guesty_Adapter implements PBE_Platform_Interface {

    private $api;

    public function __construct( $client_id = '', $client_secret = '', $account_id = '', $api_endpoint = '' ) {
        $this->api = new PBE_Guesty_API( $client_id, $client_secret, $account_id, $api_endpoint );
    }

    public function authenticate() {
        return $this->api->authenticate();
    }

    public function fetch_properties( $limit = 50, $skip = 0 ) {
        return $this->api->get_listings( $limit, $skip );
    }

    /**
     * Fetch a single property with deep details
     * 
     * @param string $platform_id
     * @return array|WP_Error Standardized array or Error
     */
    public function fetch_single_property($platform_id) {
        $raw = $this->api->get_listing($platform_id);
        if ( is_wp_error($raw) ) {
            return $raw;
        }
        return $this->map_property($raw);
    }

    /**
     * Fetch and Map Reviews for a listing
     */
    public function fetch_reviews($platform_id) {
        $response = $this->api->get_reviews($platform_id);
        if ( is_wp_error($response) ) {
            error_log('PBE Guesty Adapter Error (Reviews): ' . $response->get_error_message());
            return array();
        }
        
        $raw_reviews = isset($response['data']) ? $response['data'] : array();
        $mapped = array();
        foreach ($raw_reviews as $raw) {
            $mapped[] = $this->map_review($raw);
        }
        return $mapped;
    }

    /**
     * Maps Guesty review payload to standard internal format
     */
    public function map_review($raw) {
        $mapped = array();
        $mapped['external_id'] = isset($raw['_id']) ? $raw['_id'] : '';
        $mapped['source']      = isset($raw['channelId']) ? $raw['channelId'] : 'guesty';
        $mapped['date']        = isset($raw['createdAt']) ? $raw['createdAt'] : date('Y-m-d H:i:s');
        
        $raw_review = isset($raw['rawReview']) ? $raw['rawReview'] : array();
        
        // Default values
        $mapped['author']  = 'Guest';
        $mapped['text']    = '';
        $mapped['rating']  = 5;

        if ($mapped['source'] === 'airbnb2') {
            $mapped['rating'] = isset($raw_review['overall_rating']) ? $raw_review['overall_rating'] : 5;
            $mapped['text']   = isset($raw_review['public_review']) ? $raw_review['public_review'] : '';
            // Airbnb doesn't usually expose guest name in this payload, but check guestId or generic
        } elseif ($mapped['source'] === 'bookingCom') {
            // Booking.com uses 1-10 scale, convert to 1-5 or store raw? Let's normalize to 1-5 for consistency
            $score = isset($raw_review['scoring']['review_score']) ? (float)$raw_review['scoring']['review_score'] : 10;
            $mapped['rating'] = $score / 2; 
            
            $content = isset($raw_review['content']) ? $raw_review['content'] : array();
            $mapped['text']   = isset($content['positive']) ? $content['positive'] : '';
            if (isset($content['negative']) && $content['negative'] !== 'Nothing') {
                $mapped['text'] .= "\n\nNegative: " . $content['negative'];
            }
            if (isset($raw_review['reviewer']['name'])) {
                $mapped['author'] = $raw_review['reviewer']['name'];
            }
        } else {
            // Generic fallback
            $mapped['text']   = isset($raw_review['content']) ? $raw_review['content'] : '';
            $mapped['rating'] = isset($raw_review['rating']) ? $raw_review['rating'] : 5;
        }

        return $mapped;
    }

    /**
     * Maps the raw Guesty payload to our standardized format
     */
    public function map_property( $raw ) {
        $mapped = array();
        
        $mapped['platform_id']      = isset($raw['_id']) ? $raw['_id'] : '';
        $mapped['platform_source']  = 'guesty';
        
        $mapped['name']             = isset($raw['title']) ? $raw['title'] : 'Unknown Name';
        
        // Summary / Descriptions
        $desc = isset($raw['publicDescription']) ? $raw['publicDescription'] : null;
        if ( is_array($desc) ) {
            $mapped['description'] = isset($desc['summary']) ? $desc['summary'] : '';
            $mapped['house_rules'] = isset($desc['houseRules']) ? $desc['houseRules'] : '';
        } else {
            $mapped['description'] = (string) $desc;
            $mapped['house_rules'] = '';
        }
        
        // Pricing
        $mapped['price']            = isset($raw['prices']['basePrice']) ? $raw['prices']['basePrice'] : 0;
        
        // Accommodations
        $mapped['bedrooms']         = isset($raw['bedrooms']) ? $raw['bedrooms'] : 1;
        $mapped['bathrooms']        = isset($raw['bathrooms']) ? $raw['bathrooms'] : 1;
        $mapped['max_guests']       = isset($raw['accommodates']) ? $raw['accommodates'] : 1;
        $mapped['property_type']    = isset($raw['propertyType']) ? $raw['propertyType'] : '';
        
        // Additional Properties
        $mapped['tags'] = array();
        if ( isset($raw['tags']) && is_array($raw['tags']) ) {
            foreach ( $raw['tags'] as $tag ) {
                $tag_str = sanitize_text_field( $tag );
                if ( $tag_str !== '' ) {
                    $mapped['tags'][] = ucwords( strtolower( $tag_str ) );
                }
            }
        }
        $mapped['active']           = isset($raw['active']) ? (bool) $raw['active'] : false;
        $mapped['room_type']        = isset($raw['roomType']) ? $raw['roomType'] : '';
        $mapped['is_listed']        = isset($raw['isListed']) ? (bool) $raw['isListed'] : false;
        $mapped['area_square_feet'] = isset($raw['areaSquareFeet']) ? $raw['areaSquareFeet'] : '';
        // house_rules is mapped above now
        
        // Location
        $mapped['lat']              = isset($raw['address']['lat']) ? $raw['address']['lat'] : '';
        $mapped['area_square_feet'] = isset($raw['area']['sqft']) ? $raw['area']['sqft'] : '';
        $mapped['currency']         = isset($raw['currency']) ? $raw['currency'] : 'USD';
        
        $mapped['lng']              = isset($raw['address']['lng']) ? $raw['address']['lng'] : '';
        $mapped['full_address']     = isset($raw['address']['full']) ? $raw['address']['full'] : '';
        $mapped['street']           = isset($raw['address']['street']) ? $raw['address']['street'] : '';
        $mapped['city']             = isset($raw['address']['city']) ? $raw['address']['city'] : '';
        $mapped['state']            = isset($raw['address']['state']) ? $raw['address']['state'] : '';
        $mapped['country']          = isset($raw['address']['country']) ? $raw['address']['country'] : '';
        $mapped['zip']              = isset($raw['address']['zipcode']) ? $raw['address']['zipcode'] : (isset($raw['address']['zip']) ? $raw['address']['zip'] : '');

        // Amenities JSON & Flat Array
        $mapped['amenities_json']   = array();
        $mapped['amenities']        = array();
        if (isset($raw['amenities']) && is_array($raw['amenities'])) {
            foreach($raw['amenities'] as $amenity) {
                // Formatting for possible Icon or Label mapping
                $amenity_name = is_array($amenity) && isset($amenity['name']) ? $amenity['name'] : $amenity;
                $sanitized = sanitize_text_field($amenity_name);
                
                if (trim($sanitized) !== '') {
                    $mapped['amenities_json'][] = array(
                        'name' => $sanitized,
                        'icon' => sanitize_title($sanitized) 
                    );
                    $mapped['amenities'][] = $sanitized;
                }
            }
        }
        
        // Gallery URLs
        $mapped['gallery_urls'] = array();
        if (isset($raw['pictures']) && is_array($raw['pictures'])) {
            foreach($raw['pictures'] as $pic) {
                if (isset($pic['original'])) {
                    $mapped['gallery_urls'][] = $pic['original'];
                }
            }
        }
        
        // Optional Booking stubs availability
        $mapped['has_availability'] = true; 
        $mapped['min_nights']       = isset($raw['minNights']) ? intval($raw['minNights']) : 1;

        return $mapped;
    }

    /**
     * Fetch supported amenity groups from Guesty and create hierarchical WordPress taxonomies.
     * This establishes the groups as Parent terms and amenities as Child terms.
     */
    public function sync_amenity_groups() {
        if ( ! taxonomy_exists('amenity') ) {
            return false;
        }

        $supported = $this->api->get_supported_amenities();
        if ( is_wp_error($supported) || empty($supported) ) {
            return false;
        }

        // Handle Guesty's flat array of objects (e.g. [{"name": "Bed", "group": "Bedroom"}])
        foreach ( $supported as $amenity_item ) {
            if ( isset($amenity_item['name']) ) {
                $am_name = sanitize_text_field($amenity_item['name']);
                $cat_name = !empty($amenity_item['group']) ? sanitize_text_field($amenity_item['group']) : 'Other';
                
                // Create or pull Parent Term
                if ( ! term_exists( $cat_name, 'amenity' ) ) {
                    wp_insert_term( $cat_name, 'amenity', array( 'slug' => sanitize_title($cat_name) ) );
                }
                
                $parent_term = get_term_by( 'name', $cat_name, 'amenity' );
                $parent_term_id = $parent_term ? $parent_term->term_id : 0;
                
                if ( $parent_term_id ) {
                    // Tag parent category
                    $this->tag_term_with_platform($parent_term_id, 'guesty');

                    // Check if child term exists ANYWHERE
                    $child_term = term_exists($am_name, 'amenity');
                    
                    if ( $child_term ) {
                        $child_id = is_array($child_term) ? $child_term['term_id'] : $child_term;
                        
                        // Move it to the correct group if it's currently orphaned or in the wrong place
                        $child_obj = get_term($child_id, 'amenity');
                        if ( $child_obj ) {
                            $is_other_fallback = ($cat_name === 'Other');
                            $has_no_parent = ($child_obj->parent == 0);

                            if (!$is_other_fallback || $has_no_parent) {
                                if ( $child_obj->parent != $parent_term_id ) {
                                    wp_update_term( $child_id, 'amenity', array(
                                        'parent' => $parent_term_id
                                    ));
                                }
                            }
                        }
                    } else {
                        // Create it from scratch with the correct parent
                        $new_child = wp_insert_term( $am_name, 'amenity', array(
                            'parent' => $parent_term_id,
                            'slug'   => sanitize_title($am_name)
                        ));
                        $child_id = !is_wp_error($new_child) ? $new_child['term_id'] : 0;
                    }

                    if ($child_id) {
                        // Tag child amenity
                        $this->tag_term_with_platform($child_id, 'guesty');
                    }
                }
            }
        }
        return true;
    }

    /**
     * Tags a term with a platform source metadata
     */
    private function tag_term_with_platform($term_id, $platform) {
        $platforms = get_term_meta($term_id, 'pbe_platform_source', true);
        if (empty($platforms)) {
            $platforms = array();
        }
        if (!is_array($platforms)) {
            $platforms = array($platforms);
        }
        if (!in_array($platform, $platforms)) {
            $platforms[] = $platform;
            update_term_meta($term_id, 'pbe_platform_source', $platforms);
        }
    }

    /**
     * Fetch a quote and map to internal format
     */
    public function fetch_quote($platform_id, $from, $to, $guests, $force_refresh = false) {
        $cache_key = 'pbe_quote_' . $platform_id . '_' . $from . '_' . $to . '_' . $guests;
        $cached    = get_transient($cache_key);

        if ( ! $force_refresh && $cached !== false ) {
            return $cached;
        }

        $raw = $this->api->get_quote($platform_id, $from, $to, $guests);
        if ( is_wp_error($raw) ) {
            return $raw;
        }

        $mapped = $this->map_quote($raw);

        // Cache for 15 minutes or custom duration
        $duration = intval(get_option('pbe_cache_duration', 15)) * MINUTE_IN_SECONDS;
        if ( $duration > 0 ) {
            set_transient($cache_key, $mapped, $duration);
        }

        return $mapped;
    }

    /**
     * Maps Guesty Quote response to Internal standard
     */
    private function map_quote($raw) {
        // Guesty v1 /quotes returns an array of quote objects
        $quote = isset($raw[0]) ? $raw[0] : (isset($raw['data']) ? $raw['data'] : $raw);
        
        // Navigate the deep nesting confirmed in logs: 
        // rates -> ratePlans[0] -> money -> money
        $rates     = isset($quote['rates']) ? $quote['rates'] : array();
        $ratePlans = isset($rates['ratePlans']) ? $rates['ratePlans'] : array();
        $firstPlan = isset($ratePlans[0]) ? $ratePlans[0] : array();
        $moneyWrap = isset($firstPlan['money']) ? $firstPlan['money'] : array();
        $moneyData = isset($moneyWrap['money']) ? $moneyWrap['money'] : array();

        // Fallback to legacy structure if new nesting not found
        if ( empty($moneyData) ) {
            $moneyData = isset($quote['money']) ? $quote['money'] : array();
        }

        $mapped = array(
            'total'       => isset($moneyData['hostPayout']) ? floatval($moneyData['hostPayout']) : 0,
            'net'         => isset($moneyData['subTotalPrice']) ? floatval($moneyData['subTotalPrice']) : 0,
            'currency'    => isset($moneyData['currency']) ? $moneyData['currency'] : 'USD',
            'breakdown'   => array()
        );

        // Map breakdown from invoiceItems (v1 /quotes standard)
        // High-level check: invoiceItems is usually in the money wrapper object
        $items = array();
        if ( isset($moneyWrap['invoiceItems']) && is_array($moneyWrap['invoiceItems']) ) {
            $items = $moneyWrap['invoiceItems'];
        } elseif ( isset($moneyData['invoiceItems']) && is_array($moneyData['invoiceItems']) ) {
            $items = $moneyData['invoiceItems'];
        }

        if ( ! empty($items) ) {
            foreach ( $items as $item ) {
                $mapped['breakdown'][] = array(
                    'label'  => isset($item['title']) ? $item['title'] : 'Fee',
                    'amount' => isset($item['amount']) ? floatval($item['amount']) : 0,
                );
            }
        } 
        // Fallback for legacy breakdown
        elseif ( isset($quote['breakdown']) && is_array($quote['breakdown']) ) {
            foreach ( $quote['breakdown'] as $item ) {
                $mapped['breakdown'][] = array(
                    'label'  => isset($item['description']) ? $item['description'] : 'Extra Fee',
                    'amount' => isset($item['value']) ? floatval($item['value']) : 0,
                );
            }
        }

        return $mapped;
    }

    /**
     * Create a new reservation on Guesty
     */
    public function create_reservation( $platform_id, $checkin, $checkout, $guests, $guest_data, $quote = array() ) {
        return $this->api->create_reservation( $platform_id, $checkin, $checkout, $guests, $guest_data, $quote );
    }

    /**
     * Cancel an existing reservation on Guesty
     */
    public function cancel_reservation( $reservation_id ) {
        return $this->api->cancel_reservation( $reservation_id );
    }

    /**
     * Fetch real-time availability calendar
     */
    public function fetch_availability( $platform_id, $from, $to, $force_refresh = false ) {
        return $this->api->get_availability( $platform_id, $from, $to );
    }


    public function purge_quote_cache( $platform_id ) {
        global $wpdb;
        $prefix = '_transient_pbe_quote_' . $platform_id . '_';
        $keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
        
        if ( ! empty($keys) ) {
            foreach ( $keys as $key ) {
                $transient = str_replace( '_transient_', '', $key );
                delete_transient( $transient );
            }
        }
    }
}
