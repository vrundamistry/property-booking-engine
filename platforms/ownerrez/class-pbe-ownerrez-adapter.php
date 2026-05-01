<?php
/**
 * OwnerRez Platform Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_OwnerRez_Adapter implements PBE_Platform_Interface {

    private $api;

    public function __construct( $basic_auth ) {
        $this->api = new PBE_OwnerRez_API( $basic_auth );
    }

    public function authenticate() {
        $response = $this->api->get_properties( 1, 0 );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return true;
    }

    public function fetch_properties( $limit = 50, $skip = 0 ) {
        $properties_response = $this->api->get_properties( $limit, $skip );
        if ( is_wp_error( $properties_response ) ) return $properties_response;

        $listings_response = $this->api->get_listings( $limit, $skip );
        if ( is_wp_error( $listings_response ) ) return $listings_response;

        $properties = isset( $properties_response['items'] ) ? $properties_response['items'] : (isset($properties_response['properties']) ? $properties_response['properties'] : $properties_response);
        $listings   = isset( $listings_response['items'] ) ? $listings_response['items'] : (isset($listings_response['properties']) ? $listings_response['properties'] : $listings_response);

        $merged = array();
        $listings_by_id = array();
        
        if ( is_array($listings) ) {
            foreach ( $listings as $listing ) {
                if ( isset($listing['property_id']) ) {
                    $listings_by_id[$listing['property_id']] = $listing;
                } elseif ( isset($listing['propertyId']) ) {
                    $listings_by_id[$listing['propertyId']] = $listing;
                } elseif ( isset($listing['id']) ) {
                    $listings_by_id[$listing['id']] = $listing;
                }
            }
        }

        if ( is_array($properties) ) {
            foreach ( $properties as $prop ) {
                $id = isset($prop['id']) ? $prop['id'] : '';
                $merged[] = array(
                    'property' => $prop,
                    'listing'  => isset($listings_by_id[$id]) ? $listings_by_id[$id] : array()
                );
            }
        }

        return $merged;
    }

    public function fetch_single_property( $platform_id ) {
        $property_raw = $this->api->get_property( $platform_id );
        if ( is_wp_error( $property_raw ) ) return $property_raw;

        $listing_raw = $this->api->get_listing( $platform_id );
        if ( is_wp_error( $listing_raw ) ) return $listing_raw;

        $raw_merged = array(
            'property' => $property_raw,
            'listing'  => $listing_raw
        );
        
        return $this->map_property( $raw_merged );
    }

    private function format_property_type($type_raw) {
        if ( empty($type_raw) ) return '';
        
        $overrides = array(
            'bed_and_breakfast' => 'Bed & Breakfast',
            'caravan_rv'        => 'Caravan/RV',
            'rv'                => 'RV',
            'b_and_b'           => 'B&B'
        );

        $type_lower = strtolower($type_raw);
        if ( isset($overrides[$type_lower]) ) {
            return $overrides[$type_lower];
        }

        return ucwords(str_replace('_', ' ', $type_lower));
    }

    private function format_amenity_group_name($caption) {
        if ( empty($caption) ) return 'Other';
        
        $caption = sanitize_text_field($caption);
        
        if ( $caption === "Where you'll sleep" ) {
            return 'Bedrooms';
        }
        
        if ( $caption === 'Setting & View' ) {
            return 'Location Type';
        }
        
        return $caption;
    }

    public function map_property( $raw_merged ) {
        $property_data = isset($raw_merged['property']) ? $raw_merged['property'] : $raw_merged;
        $listing_data  = isset($raw_merged['listing']) ? $raw_merged['listing'] : array();

        $images = array();
        if ( !empty( $listing_data['photos'] ) ) {
            foreach ( $listing_data['photos'] as $img ) {
                if ( !empty($img['large_url']) ) {
                    $images[] = $img['large_url'];
                } elseif ( !empty($img['original_url']) ) {
                    $images[] = $img['original_url'];
                }
            }
        } elseif ( !empty( $property_data['thumbnail_url_large'] ) ) {
            $images[] = $property_data['thumbnail_url_large'];
        }

        $amenities = array();
        $amenities_json = array();
        
        $ignored_categories = array('House Rules');
        
        if ( !empty( $listing_data['amenity_categories'] ) ) {
            foreach ( $listing_data['amenity_categories'] as $cat ) {
                if ( !empty($cat['caption']) && in_array( $cat['caption'], $ignored_categories ) ) {
                    continue;
                }
                
                $group_name = isset($cat['caption']) ? $this->format_amenity_group_name($cat['caption']) : 'Other';
                if ( !empty($cat['amenities']) ) {
                    foreach ( $cat['amenities'] as $amenity ) {
                        if ( isset($amenity['text']) ) {
                            $sanitized = sanitize_text_field($amenity['text']);
                            $amenities[] = array(
                                'name' => $sanitized,
                                'group' => $group_name
                            );
                            $amenities_json[] = array(
                                'name' => $sanitized,
                                'icon' => sanitize_title($sanitized)
                            );
                        }
                    }
                }
            }
        }

        // Build description
        $description = '';
        if ( !empty($listing_data['descriptions']['short_description']) ) {
            $description .= $listing_data['descriptions']['short_description'] . "\n\n";
        }
        if ( !empty($listing_data['descriptions']['accommodations_detail']) ) {
            $description .= $listing_data['descriptions']['accommodations_detail'];
        }
        
        $house_rules = !empty($listing_data['descriptions']['features_description']) ? $listing_data['descriptions']['features_description'] : '';

        return array(
            'platform_id'      => isset($property_data['id']) ? (string)$property_data['id'] : '',
            'platform_source'  => 'ownerrez',
            'name'             => isset($property_data['name']) ? $property_data['name'] : '',
            'description'      => $description,
            'house_rules'      => $house_rules,
            'bedrooms'         => isset($property_data['bedrooms']) ? intval($property_data['bedrooms']) : 0,
            'bathrooms'        => isset($property_data['bathrooms']) ? floatval($property_data['bathrooms']) : 0,
            'max_guests'       => isset($property_data['max_guests']) ? intval($property_data['max_guests']) : 0,
            'price'            => isset($listing_data['nightly_rate_min']) ? floatval($listing_data['nightly_rate_min']) : 0,
            'currency'         => isset($property_data['currency_code']) ? $property_data['currency_code'] : 'USD',
            'lat'              => isset($property_data['latitude']) ? (string)$property_data['latitude'] : '',
            'lng'              => isset($property_data['longitude']) ? (string)$property_data['longitude'] : '',
            
            // Address
            'street'           => isset($property_data['address']['street1']) ? $property_data['address']['street1'] : '',
            'city'             => isset($property_data['address']['city']) ? $property_data['address']['city'] : '',
            'state'            => isset($property_data['address']['state']) ? $property_data['address']['state'] : '',
            'country'          => isset($property_data['address']['country']) ? $property_data['address']['country'] : '',
            'zip'              => isset($property_data['address']['postal_code']) ? $property_data['address']['postal_code'] : '',
            
            // Setup
            'check_in_start'   => isset($property_data['check_in']) ? $property_data['check_in'] : '',
            'check_out'        => isset($property_data['check_out']) ? $property_data['check_out'] : '',
            'property_type'    => isset($property_data['property_type']) ? $this->format_property_type($property_data['property_type']) : '',
            'area_size'        => isset($property_data['living_area']) ? $property_data['living_area'] : '',
            'area_size_unit'   => isset($property_data['living_area_type']) ? $property_data['living_area_type'] : '',
            'sleeps_min'       => isset($listing_data['sleeps_min']) ? intval($listing_data['sleeps_min']) : 0,
            'sleeps_max'       => isset($listing_data['sleeps_max']) ? intval($listing_data['sleeps_max']) : 0,
            'propertyId_key'   => isset($property_data['key']) ? $property_data['key'] : '',
            
            'amenities'        => $amenities,
            'amenities_json'   => $amenities_json,
            'images'           => $images,
            'gallery_urls'     => $images,
            'active'           => isset($property_data['active']) ? (bool)$property_data['active'] : true,
        );
    }

    public function sync_amenity_groups($selected_ids = array()) {
        if ( ! taxonomy_exists('amenity') ) {
            return false;
        }

        $listings_response = $this->api->get_listings( 100, 0 );
        if ( is_wp_error( $listings_response ) ) {
            return false;
        }

        $listings = isset( $listings_response['items'] ) ? $listings_response['items'] : (isset($listings_response['properties']) ? $listings_response['properties'] : $listings_response);

        if ( empty($listings) || !is_array($listings) ) {
            return false;
        }

        $ignored_categories = array('House Rules');

        foreach ( $listings as $listing ) {
            $listing_id = isset($listing['property_id']) ? (string)$listing['property_id'] : (isset($listing['propertyId']) ? (string)$listing['propertyId'] : (isset($listing['id']) ? (string)$listing['id'] : ''));
            if ( !empty($selected_ids) && !in_array($listing_id, $selected_ids) ) {
                continue;
            }

            if ( !empty($listing['amenity_categories']) ) {
                foreach ( $listing['amenity_categories'] as $category ) {
                    if ( !empty($category['caption']) && in_array( $category['caption'], $ignored_categories ) ) {
                        continue;
                    }
                    
                    $cat_name = isset($category['caption']) ? $this->format_amenity_group_name($category['caption']) : 'Other';

                    if ( ! term_exists( $cat_name, 'amenity' ) ) {
                        wp_insert_term( $cat_name, 'amenity', array( 'slug' => sanitize_title($cat_name) ) );
                    }

                    $parent_term = get_term_by( 'name', $cat_name, 'amenity' );
                    $parent_term_id = $parent_term ? $parent_term->term_id : 0;

                    if ( $parent_term_id ) {
                        $this->tag_term_with_platform($parent_term_id, 'ownerrez');

                        if ( !empty($category['amenities']) ) {
                            foreach ( $category['amenities'] as $amenity ) {
                                if ( !empty($amenity['text']) ) {
                                    $am_name = sanitize_text_field($amenity['text']);
                                    
                                    $child_term = term_exists($am_name, 'amenity');
                                    
                                    if ( $child_term ) {
                                        $child_id = is_array($child_term) ? $child_term['term_id'] : $child_term;
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
                                        $new_child = wp_insert_term( $am_name, 'amenity', array(
                                            'parent' => $parent_term_id,
                                            'slug'   => sanitize_title($am_name)
                                        ));
                                        $child_id = !is_wp_error($new_child) ? $new_child['term_id'] : 0;
                                    }

                                    if ($child_id) {
                                        $this->tag_term_with_platform($child_id, 'ownerrez');
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return true;
    }

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

    public function fetch_reviews( $platform_id ) {
        $response = $this->api->get_reviews( $platform_id );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $items = isset( $response['items'] ) ? $response['items'] : array();
        
        $reviews = array();
        foreach ( $items as $item ) {
            $date = isset( $item['created_utc'] ) ? $item['created_utc'] : ( isset( $item['date'] ) ? $item['date'] : '' );
            
            $author = 'Guest';
            if ( !empty($item['display_name']) ) {
                $author = $item['display_name'];
            } elseif ( !empty($item['guest']) && !empty($item['guest']['first_name']) ) {
                $author = trim( $item['guest']['first_name'] . ' ' . (isset($item['guest']['last_name']) ? $item['guest']['last_name'] : '') );
            }
            if ( empty($author) ) $author = 'Guest';

            $reviews[] = array(
                'external_id'    => isset( $item['id'] ) ? (string) $item['id'] : '',
                'author'         => $author,
                'text'           => isset( $item['body'] ) ? $item['body'] : '',
                'date'           => $date,
                'rating'         => isset( $item['stars'] ) ? floatval( $item['stars'] ) : 5,
                'source'         => 'ownerrez',
                'listing_site'   => isset( $item['listing_site'] ) ? $item['listing_site'] : '',
                'reservation_id' => isset( $item['booking_id'] ) ? (string) $item['booking_id'] : ''
            );
        }

        return $reviews;
    }

    public function fetch_quote( $platform_id, $from, $to, $guests, $force_refresh = false ) {
        return new WP_Error( 'not_implemented', 'Quote fetching not yet implemented for OwnerRez.' );
    }

    public function fetch_availability( $platform_id, $from, $to, $force_refresh = false ) {
        $all_bookings = array();
        
        $limit = 100;
        $offset = 0;
        $has_more = true;
        
        while ( $has_more ) {
            $response = $this->api->get_bookings( $platform_id, $limit, $offset );
            
            if ( is_wp_error( $response ) ) {
                // Return immediately if there's an error on the first page, otherwise break
                if ( empty($all_bookings) ) {
                    return $response;
                }
                break;
            }
            
            $items = isset( $response['items'] ) ? $response['items'] : array();
            if ( !empty($items) ) {
                $all_bookings = array_merge( $all_bookings, $items );
            }
            
            // Check pagination
            if ( isset( $response['next_page_url'] ) && !empty( $response['next_page_url'] ) ) {
                $offset += $limit;
            } else if ( count( $items ) === $limit ) {
                $offset += $limit;
            } else {
                $has_more = false;
            }
        }
        
        $days = array();
        
        try {
            $current = new DateTime($from);
            $end = new DateTime($to);
            
            while ( $current <= $end ) {
                $date_str = $current->format('Y-m-d');
                $status = 'available';
                $guests = 1;
                
                foreach ( $all_bookings as $booking ) {
                    $arrival = isset($booking['arrival']) ? substr($booking['arrival'], 0, 10) : '';
                    $departure = isset($booking['departure']) ? substr($booking['departure'], 0, 10) : '';
                    
                    if ( !empty($arrival) && !empty($departure) ) {
                        // The booking blocks nights starting from arrival, up to but NOT including departure
                        if ( $date_str >= $arrival && $date_str < $departure ) {
                            $status = 'booked';
                            $guests_count = (isset($booking['adults']) ? intval($booking['adults']) : 0) + (isset($booking['children']) ? intval($booking['children']) : 0);
                            if ($guests_count > 0) {
                                $guests = $guests_count;
                            }
                            break;
                        }
                    }
                }
                
                $days[] = array(
                    'date'       => $date_str,
                    'status'     => $status,
                    'minNights'  => 1, // Fallback
                    'guests'     => $guests
                );
                
                $current->modify('+1 day');
            }
        } catch (Exception $e) {
            return new WP_Error( 'date_error', $e->getMessage() );
        }
        
        return array( 'days' => $days );
    }

    public function create_reservation( $platform_id, $checkin, $checkout, $guests, $guest_data, $quote = array() ) {
        return new WP_Error( 'not_implemented', 'Reservations not yet implemented for OwnerRez.' );
    }

    public function purge_quote_cache( $platform_id ) {
        // Do nothing
    }
}
