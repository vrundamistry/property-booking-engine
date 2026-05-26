<?php
/**
 * Hostfully Platform Adapter
 * Implements PBE_Platform_Interface for Hostfully integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Hostfully_Adapter implements PBE_Platform_Interface {

    private $api;
    private $agency_uid;

    public function __construct( $api_key, $agency_uid ) {
        $this->api = new PBE_Hostfully_API( $api_key );
        $this->agency_uid = $agency_uid;
    }

    public function authenticate() {
        return $this->api->authenticate();
    }

    /**
     * Fetch all properties for the agency
     * Uses transients to bridge Hostfully's cursor-based pagination with the importer's skip-based loop.
     * Updated: Performs a "deep fetch" for each property to ensure full details (Photos, Amenities, Descriptions).
     */
    public function fetch_properties( $limit = 50, $skip = 0 ) {
        $cursor = '';
        
        // Use a shortened transient name to avoid hitting the WordPress 64-character limit for option_name
        $transient_key = 'pbe_hf_cur_' . md5( $this->agency_uid );
        
        if ( $skip > 0 ) {
            $cursor = get_transient( $transient_key );
            error_log( "PBE DEBUG: Loaded cursor from transient ($transient_key): " . $cursor );
            if ( ! $cursor ) {
                return array(); 
            }
        }

        $response = $this->api->get_listings( $this->agency_uid, $limit, $cursor );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Save cursor for next batch
        if ( ! empty( $response['_paging']['_nextCursor'] ) ) {
            error_log( "PBE DEBUG: Saving next cursor to transient ($transient_key): " . $response['_paging']['_nextCursor'] );
            set_transient( $transient_key, $response['_paging']['_nextCursor'], 10 * MINUTE_IN_SECONDS );
        } else {
            delete_transient( $transient_key );
        }

        $raw_list = isset( $response['properties'] ) ? $response['properties'] : array();
        $enriched = array();

        // Perform Deep Fetch for each property in the list
        // This ensures "All Properties" sync matches the quality of "Selected IDs" sync
        foreach ( $raw_list as $prop ) {
            if ( empty( $prop['uid'] ) ) continue;

            $full_data = $this->fetch_single_property( $prop['uid'] );
            if ( ! is_wp_error( $full_data ) ) {
                // Signal to PBE_Importer that this is already mapped and enriched
                $enriched[] = array( '_pre_mapped' => $full_data );
            }
            
            // Add a small delay to avoid hitting Hostfully API rate limits during bulk sync
            usleep( 100000 ); // 100ms
        }

        return $enriched;
    }

    /**
     * Fetch a single property with full details (Photos + Amenities + Descriptions)
     */
    public function fetch_single_property( $platform_id ) {
        // 1. Get Core Property Info
        $response = $this->api->get_property( $platform_id, $this->agency_uid );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Hostfully returns the single property wrapped in a 'property' key
        $raw_property = isset( $response['property'] ) ? $response['property'] : $response;

        // 2. Get Full Gallery
        $photos_response = $this->api->get_photos( $platform_id );
        $photos = array();
        if ( ! is_wp_error( $photos_response ) && isset( $photos_response['photos'] ) ) {
            foreach ( $photos_response['photos'] as $photo ) {
                $photos[] = ! empty( $photo['originalImageUrl'] ) ? $photo['originalImageUrl'] : $photo['largeScaleImageUrl'];
            }
        }

        // 3. Get Amenities
        $amenities_response = $this->api->get_amenities( $platform_id );
        $amenities = array();
        if ( ! is_wp_error( $amenities_response ) && isset( $amenities_response['amenities'] ) ) {
            foreach ( $amenities_response['amenities'] as $amn ) {
                $amenities[] = array(
                    'name'  => $this->format_amenity_name( $amn['amenity'] ),
                    'group' => $this->format_category_name( $amn['category'] )
                );
            }
        }

        // 4. Get Descriptions (New: Requested by user)
        $desc_response = $this->api->get_property_descriptions( $platform_id );
        $descriptions = array();
        if ( ! is_wp_error( $desc_response ) && isset( $desc_response['propertyDescriptions'][0] ) ) {
            $descriptions = $desc_response['propertyDescriptions'][0];
        }

        // Merge and Map
        return $this->map_property( $raw_property, $photos, $amenities, $descriptions );
    }

    /**
     * Map raw Hostfully data to standardized PBE format
     */
    public function map_property( $raw, $photos = array(), $amenities = array(), $descriptions = array() ) {
        // Fallback for photos if not provided via secondary call
        if ( empty( $photos ) && ! empty( $raw['pictureLink'] ) ) {
            $photos = array( $raw['pictureLink'] );
        }

        // Use descriptions if provided (Requested by user)
        $name = ! empty( $descriptions['name'] ) ? $descriptions['name'] : ( ! empty( $raw['name'] ) ? $raw['name'] : '' );
        $description = ! empty( $descriptions['summary'] ) ? $descriptions['summary'] : ( ! empty( $raw['description'] ) ? $raw['description'] : '' );

        return array(
            'platform_id'     => $raw['uid'],
            'platform_source' => 'hostfully',
            'active'          => isset( $raw['isActive'] ) ? $raw['isActive'] : true,
            'name'            => $name,
            'description'     => $description,
            'price'           => isset( $raw['pricing']['dailyRate'] ) ? floatval( $raw['pricing']['dailyRate'] ) : 0,
            'bedrooms'        => isset( $raw['bedrooms'] ) ? intval( $raw['bedrooms'] ) : 0,
            'bathrooms'       => isset( $raw['bathrooms'] ) ? floatval( $raw['bathrooms'] ) : 0,
            'max_guests'      => isset( $raw['availability']['maxGuests'] ) ? intval( $raw['availability']['maxGuests'] ) : 1,
            'min_nights'      => isset( $raw['availability']['minimumStay'] ) ? intval( $raw['availability']['minimumStay'] ) : 1,
            'max_nights'      => isset( $raw['availability']['maximumStay'] ) ? intval( $raw['availability']['maximumStay'] ) : 0,
            'currency'        => isset( $raw['pricing']['currency'] ) ? $raw['pricing']['currency'] : 'USD',
            'lat'             => isset( $raw['address']['latitude'] ) ? $raw['address']['latitude'] : '',
            'lng'             => isset( $raw['address']['longitude'] ) ? $raw['address']['longitude'] : '',
            'street'          => isset( $raw['address']['address'] ) ? $raw['address']['address'] : '',
            'address2'        => isset( $raw['address']['address2'] ) ? $raw['address']['address2'] : ( isset( $raw['address']['addressComplement'] ) ? $raw['address']['addressComplement'] : '' ),
            'city'            => isset( $raw['address']['city'] ) ? $raw['address']['city'] : '',
            'state'           => isset( $raw['address']['state'] ) ? $raw['address']['state'] : '',
            'zip'             => isset( $raw['address']['zipCode'] ) ? $raw['address']['zipCode'] : '',
            'country_code'    => isset( $raw['address']['countryCode'] ) ? $raw['address']['countryCode'] : '',
            'property_type'   => isset( $raw['propertyType'] ) ? ucwords( strtolower( str_replace( '_', ' ', $raw['propertyType'] ) ) ) : 'Apartment',
            'amenities'       => $amenities,
            'gallery_urls'    => $photos,
            'cleaning_fee'    => isset( $raw['pricing']['cleaningFee'] ) ? floatval( $raw['pricing']['cleaningFee'] ) : 0,
            'check_in_start'  => isset( $raw['availability']['checkInTimeStart'] ) ? $raw['availability']['checkInTimeStart'] : '',
            'check_in_end'    => isset( $raw['availability']['checkInTimeEnd'] ) ? $raw['availability']['checkInTimeEnd'] : '',
            'check_out'       => isset( $raw['availability']['checkOutTime'] ) ? $raw['availability']['checkOutTime'] : '',
            'area_size'       => isset( $raw['area']['size'] ) ? $raw['area']['size'] : '',
            'area_unit'       => isset( $raw['area']['unitType'] ) ? $raw['area']['unitType'] : 'SQUARE_FEET',
        );
    }

    /**
     * Formatting Helpers
     */
    private function format_amenity_name( $slug ) {
        if ( empty( $slug ) ) return '';
        $name = str_replace( 'HAS_', '', $slug );
        $name = str_replace( '_', ' ', $name );
        return ucwords( strtolower( $name ) );
    }

    private function format_category_name( $slug ) {
        if ( empty( $slug ) ) return 'General';
        $name = str_replace( '_', ' ', $slug );
        return ucwords( strtolower( $name ) );
    }

    /**
     * Interface methods not requested for initial sync but required by Interface
     */
    public function fetch_reviews( $platform_id ) {
        $all_reviews = array();
        $cursor = '';
        $max_loops = 10; // Safety cap (200 reviews max per property to prevent timeouts)
        $loop = 0;

        while ( $loop < $max_loops ) {
            $response = $this->api->get_reviews( $platform_id, $cursor );
            if ( is_wp_error( $response ) ) {
                return empty( $all_reviews ) ? $response : $all_reviews;
            }

            $raw_reviews = isset( $response['reviews'] ) ? $response['reviews'] : array();
            
            foreach ( $raw_reviews as $rev ) {
                $all_reviews[] = array(
                    'external_id'    => isset( $rev['uid'] ) ? $rev['uid'] : ( isset( $rev['id'] ) ? $rev['id'] : '' ),
                    'author'         => ! empty( $rev['author'] ) ? $rev['author'] : ( ! empty( $rev['reviewerName'] ) ? $rev['reviewerName'] : 'Guest' ),
                    'text'           => ! empty( $rev['content'] ) ? $rev['content'] : ( isset( $rev['comment'] ) ? $rev['comment'] : '' ),
                    'rating'         => isset( $rev['rating'] ) ? floatval( $rev['rating'] ) : 5,
                    'date'           => ! empty( $rev['date'] ) ? $rev['date'] : ( isset( $rev['createdAt'] ) ? $rev['createdAt'] : date( 'Y-m-d H:i:s' ) ),
                    'title'          => ! empty( $rev['title'] ) ? $rev['title'] : '',
                    'source'         => 'hostfully',
                    'reservation_id' => '' 
                );
            }

            // Check if we have more pages
            if ( ! empty( $response['_paging']['_nextCursor'] ) ) {
                $cursor = $response['_paging']['_nextCursor'];
                $loop++;
            } else {
                break; // No more reviews
            }
        }

        return $all_reviews;
    }

    public function fetch_quote( $platform_id, $from, $to, $guests, $force_refresh = false ) {
        $cache_key = 'pbe_quote_' . $platform_id . '_' . $from . '_' . $to . '_' . $guests;
        $cached    = get_transient($cache_key);

        if ( ! $force_refresh && $cached !== false ) {
            return $cached;
        }

        $raw = $this->api->get_quote( $this->agency_uid, $platform_id, $from, $to, $guests );
        if ( is_wp_error($raw) ) {
            return $raw;
        }

        $mapped = $this->map_quote($raw);

        // Cache for 15 minutes
        $duration = intval(get_option('pbe_cache_duration', 15)) * MINUTE_IN_SECONDS;
        if ( $duration > 0 ) {
            set_transient($cache_key, $mapped, $duration);
        }

        return $mapped;
    }

    private function map_quote($raw) {
        $quote = isset($raw['quote']) ? $raw['quote'] : $raw;

        $mapped = array(
            'total'     => isset($quote['totalPrice']) ? floatval($quote['totalPrice']) : (isset($quote['totalAmount']) ? floatval($quote['totalAmount']) : 0),
            'net'       => isset($quote['rent']['rentNetPrice']) ? floatval($quote['rent']['rentNetPrice']) : 0,
            'currency'  => isset($quote['currency']) ? $quote['currency'] : 'USD',
            'breakdown' => array()
        );

        // Rent
        $rent = isset($quote['rent']['rentNetPrice']) ? floatval($quote['rent']['rentNetPrice']) : 0;
        if ( $rent > 0 ) {
            $mapped['breakdown'][] = array(
                'label'  => 'Rent',
                'amount' => $rent
            );
        }

        // Rent Taxes
        $rent_tax = isset($quote['rent']['taxAmount']) ? floatval($quote['rent']['taxAmount']) : 0;
        if ( $rent_tax > 0 ) {
            $tax_label = 'Taxes';
            if ( $rent > 0 ) {
                $percent = round( ( $rent_tax / $rent ) * 100 );
                if ( $percent > 0 ) {
                    $tax_label = "Taxes ({$percent}%)";
                }
            }
            $mapped['breakdown'][] = array(
                'label'  => $tax_label,
                'amount' => $rent_tax
            );
        }

        // Sub-Total
        if ( $rent > 0 || $rent_tax > 0 ) {
            $mapped['breakdown'][] = array(
                'label'  => 'Sub-Total',
                'amount' => $rent + $rent_tax
            );
        }

        // Cleaning Fee
        $cleaning = isset($quote['fees']['cleaningFee']['netPrice']) ? floatval($quote['fees']['cleaningFee']['netPrice']) : 0;
        if ( $cleaning > 0 ) {
            $mapped['breakdown'][] = array(
                'label'  => 'Cleaning Fee',
                'amount' => $cleaning
            );
            
            // Cleaning Fee Tax
            $cleaning_tax = isset($quote['fees']['cleaningFee']['taxAmount']) ? floatval($quote['fees']['cleaningFee']['taxAmount']) : 0;
            if ( $cleaning_tax > 0 ) {
                $c_tax_label = 'Cleaning Fee Taxes';
                if ( $cleaning > 0 ) {
                    $c_percent = round( ( $cleaning_tax / $cleaning ) * 100 );
                    if ( $c_percent > 0 ) {
                        $c_tax_label = "Cleaning Fee Taxes ({$c_percent}%)";
                    }
                }
                $mapped['breakdown'][] = array(
                    'label'  => $c_tax_label,
                    'amount' => $cleaning_tax
                );
            }
        }

        // Other Fees
        if ( isset($quote['fees']['otherFees']) && is_array($quote['fees']['otherFees']) ) {
            foreach ( $quote['fees']['otherFees'] as $fee ) {
                if ( isset($fee['amount']) && $fee['amount'] > 0 ) {
                    $mapped['breakdown'][] = array(
                        'label'  => isset($fee['name']) ? $fee['name'] : 'Fee',
                        'amount' => floatval($fee['amount'])
                    );
                }
            }
        }

        // Security Deposit
        if ( isset($quote['includeSecurityDepositInTotal']) && $quote['includeSecurityDepositInTotal'] ) {
            $deposit = isset($quote['securityDeposit']) ? floatval($quote['securityDeposit']) : 0;
            if ( $deposit > 0 ) {
                $mapped['breakdown'][] = array(
                    'label'  => 'Security Deposit',
                    'amount' => $deposit
                );
            }
        }

        return $mapped;
    }

    public function fetch_availability( $platform_id, $from, $to, $force_refresh = false ) { 
        $response = $this->api->get_calendar( $platform_id, $from, $to );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $days = array();

        if ( isset( $response['calendar']['entries'] ) && is_array( $response['calendar']['entries'] ) ) {
            foreach ( $response['calendar']['entries'] as $entry ) {
                $days[] = array(
                    'date'       => isset( $entry['date'] ) ? $entry['date'] : '',
                    'status'     => !empty( $entry['availability']['unavailable'] ) ? 'unavailable' : 'available',
                    'minNights'  => isset( $entry['availability']['minimumStayLength'] ) ? intval( $entry['availability']['minimumStayLength'] ) : 1,
                    'cta'        => (isset( $entry['availability']['availableForCheckIn'] ) && $entry['availability']['availableForCheckIn'] === false) ? 1 : 0,
                    'ctd'        => (isset( $entry['availability']['availableForCheckOut'] ) && $entry['availability']['availableForCheckOut'] === false) ? 1 : 0,
                    'price'      => isset( $entry['pricing']['value'] ) ? floatval( $entry['pricing']['value'] ) : null,
                );
            }
        }

        return array( 'days' => $days );
    }

    public function create_reservation( $platform_id, $checkin, $checkout, $guests, $guest_data, $quote = array() ) {
        // Test Mode Simulation (Matches Hostaway behavior)
        if ( get_option('pbe_test_mode') === '1' ) {
            error_log( "PBE DEBUG (Hostfully Mock): Reservation simulated for listing {$platform_id}. Dates: {$checkin} to {$checkout}. Guest: {$guest_data['first_name']} {$guest_data['last_name']}" );
            return array(
                'id'     => 'MOCK_HOSTFULLY_' . time(),
                'status' => 'BOOKED'
            );
        }

        // 1. Create Lead with actual guest information
        $response = $this->api->create_lead( $this->agency_uid, $platform_id, $checkin, $checkout, $guests, $guest_data );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $lead_uid = null;
        if ( isset( $response['lead']['uid'] ) ) {
            $lead_uid = $response['lead']['uid'];
        } elseif ( isset( $response['uid'] ) ) {
            $lead_uid = $response['uid'];
        }

        if ( ! $lead_uid ) {
            return new WP_Error( 'hostfully_lead_error', 'Failed to retrieve Lead UID from Hostfully.' );
        }

        // 2. Immediately mark the lead as booked (Confirming the reservation)
        $booked_response = $this->api->mark_as_booked( $lead_uid );
        if ( is_wp_error( $booked_response ) ) {
            return $booked_response;
        }

        return array(
            'id'     => $lead_uid,
            'status' => 'BOOKED'
        );
    }

    public function create_lead( $platform_id, $checkin, $checkout, $guests ) {
        $response = $this->api->create_lead( $this->agency_uid, $platform_id, $checkin, $checkout, $guests );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( isset( $response['lead']['uid'] ) ) {
            return $response['lead']['uid'];
        } elseif ( isset( $response['uid'] ) ) {
            return $response['uid'];
        }

        return new WP_Error( 'hostfully_lead_error', 'Failed to retrieve Lead UID from response.' );
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
