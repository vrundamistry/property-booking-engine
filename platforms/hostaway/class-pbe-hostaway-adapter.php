<?php
/**
 * Hostaway Platform Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Hostaway_Adapter implements PBE_Platform_Interface {

    private $property_types_cache = null;

    private function get_amenity_group($amenity_id, $amenity_name) {
        $name = strtolower($amenity_name);
        
        $outdoor_keywords = array(
            'outdoor', 'beach', 'lake', 'pool', 'garden', 'backyard', 'grill', 'furniture', 
            'fire pit', 'firepit', 'dock', 'jacuzzi', 'hot tub', 'boating', 'water sports', 
            'wildlife', 'waterfront', 'boat', 'kayak', 'canoe', 'veranda', 'hammock', 
            'balcony', 'parking', 'entrance', 'patio', 'deck', 'view', 'front', 'marina', 
            'golf', 'tennis', 'basketball', 'ski', 'snow', 'yard', 'exterior', 'lighting', 
            'bicycle', 'fishing', 'diving', 'hiking', 'mountain', 'rural', 'town', 'village'
        );

        $family_keywords = array(
            'children', 'infant', 'baby', 'crib', 'pack n play', 'kids', 'toddler', 
            'high chair', 'changing table', 'babysitter', 'childcare', 'toys', 
            'dinnerware', 'safety gate', 'stair gate', 'cabinet locks', 'outlet covers',
            'corner guards', 'window guards'
        );

        $indoor_keywords = array(
            'kitchen', 'tv', 'air conditioning', 'heating', 'fireplace', 'workspace', 
            'linens', 'microwave', 'oven', 'coffee', 'stove', 'refrigerator', 'utensils', 
            'hot water', 'pillows', 'blankets', 'cooking', 'shades', 'dining', 'storage', 
            'fan', 'baking', 'blender', 'freezer', 'fridge', 'wine', 'sound system', 
            'dishwasher', 'washing machine', 'dryer', 'shampoo', 'shower', 'tub', 'bidet', 
            'iron', 'hangers', 'internet', 'wifi', 'wireless', 'cable', 'toaster', 'kettle',
            'office', 'theater', 'room', 'laundry', 'sound', 'dvd', 'game', 'piano', 'record player'
        );

        foreach ($outdoor_keywords as $kw) {
            if (strpos($name, $kw) !== false) return 'Outdoor';
        }
        foreach ($family_keywords as $kw) {
            if (strpos($name, $kw) !== false) return 'Family';
        }
        foreach ($indoor_keywords as $kw) {
            if (strpos($name, $kw) !== false) return 'Indoor';
        }

        return 'Others';
    }

    public function __construct($api_key, $account_id, $manual_token = '') {
        $this->api = new PBE_Hostaway_API($api_key, $account_id, $manual_token);
    }

    private function get_property_type_name($type_id) {
        if ( is_null($this->property_types_cache) ) {
            $response = $this->api->get_property_types();
            if ( ! is_wp_error($response) && isset($response['result']) ) {
                $this->property_types_cache = array();
                foreach ( $response['result'] as $type ) {
                    if ( isset($type['id']) && isset($type['name']) ) {
                        $this->property_types_cache[$type['id']] = $type['name'];
                    }
                }
            } else {
                $this->property_types_cache = array();
            }
        }

        return isset($this->property_types_cache[$type_id]) ? $this->property_types_cache[$type_id] : '';
    }


    public function authenticate() {
        return $this->api->authenticate();
    }

    public function fetch_properties($limit = 50, $skip = 0) {
        return $this->api->get_listings($limit, $skip);
    }

    public function fetch_single_property($platform_id) {
        $response = $this->api->get_listing($platform_id);
        if ( is_wp_error($response) ) {
            return $response;
        }
        $raw = isset($response['result']) ? $response['result'] : array();
        return $this->map_property($raw);
    }

    public function map_property($raw) {
        // Handle images
        $gallery = array();
        if ( ! empty($raw['listingImages']) && is_array($raw['listingImages']) ) {
            foreach ($raw['listingImages'] as $img) {
                if ( ! empty($img['url']) ) {
                    $gallery[] = $img['url'];
                }
            }
        }

        // Handle amenities (Hostaway listing object provides names directly now)
        $amenities = array();
        if ( ! empty($raw['listingAmenities']) && is_array($raw['listingAmenities']) ) {
            foreach ($raw['listingAmenities'] as $amenity) {
                $name = isset($amenity['amenityName']) ? $amenity['amenityName'] : '';
                $id   = isset($amenity['amenityId']) ? $amenity['amenityId'] : 0;
                
                if ( ! empty($name) ) {
                    $amenities[] = array(
                        'name'  => $name,
                        'group' => $this->get_amenity_group($id, $name)
                    );
                }
            }
        }

        $property_type = '';
        if ( isset($raw['propertyTypeId']) ) {
            $property_type = $this->get_property_type_name($raw['propertyTypeId']);
        }

        // Fallback to direct propertyType string if ID lookup failed or ID was missing
        if ( empty($property_type) && ! empty($raw['propertyType']) ) {
            $property_type = ucwords( strtolower( str_replace( '_', ' ', $raw['propertyType'] ) ) );
        }

        if ( empty($property_type) ) {
            $property_type = 'Property';
        }

        $mapped = array(
            'platform_id'     => (string) (isset($raw['id']) ? $raw['id'] : ''),
            'platform_source' => 'hostaway',
            'name'            => isset($raw['name']) ? $raw['name'] : '',
            'description'     => ! empty($raw['description']) ? $raw['description'] : (! empty($raw['airbnbSummary']) ? $raw['airbnbSummary'] : ''),
            'price'           => isset($raw['price']) ? floatval($raw['price']) : 0,
            'bedrooms'        => isset($raw['bedroomsNumber']) ? intval($raw['bedroomsNumber']) : 0,
            'bathrooms'       => isset($raw['bathroomsNumber']) ? floatval($raw['bathroomsNumber']) : 0,
            'max_guests'      => isset($raw['personCapacity']) ? intval($raw['personCapacity']) : 1,
            'min_nights'      => isset($raw['minNights']) ? intval($raw['minNights']) : 1,
            'max_nights'      => isset($raw['maxNights']) ? intval($raw['maxNights']) : 0,
            'lat'             => isset($raw['lat']) ? $raw['lat'] : '',
            'lng'             => isset($raw['lng']) ? $raw['lng'] : '',
            'full_address'    => isset($raw['address']) ? $raw['address'] : '',
            'public_address'  => isset($raw['publicAddress']) ? $raw['publicAddress'] : '',
            'street'          => isset($raw['street']) ? $raw['street'] : '',
            'city'            => isset($raw['city']) ? $raw['city'] : '',
            'state'           => isset($raw['state']) ? $raw['state'] : '',
            'country'         => isset($raw['country']) ? $raw['country'] : '',
            'country_code'    => isset($raw['countryCode']) ? $raw['countryCode'] : '',
            'zip'             => isset($raw['zipcode']) ? $raw['zipcode'] : '',
            'house_rules'     => isset($raw['houseRules']) ? $raw['houseRules'] : '',
            'amenities'       => $amenities,
            'gallery_urls'    => $gallery,
            'property_type'   => $property_type,
            'room_type'       => isset($raw['roomType']) ? $raw['roomType'] : '',
            'currency'        => isset($raw['currencyCode']) ? $raw['currencyCode'] : 'USD',
            // New fields requested
            'booking_engine_markup' => isset($raw['bookingEngineMarkup']) ? floatval($raw['bookingEngineMarkup']) : 0,
            'damage_deposit'        => isset($raw['refundableDamageDeposit']) ? floatval($raw['refundableDamageDeposit']) : 0,
            'instant_bookable'      => isset($raw['instantBookable']) ? intval($raw['instantBookable']) : 0,
            'cleaning_fee'          => isset($raw['cleaningFee']) ? floatval($raw['cleaningFee']) : 0,
            'check_in_start'        => isset($raw['checkInTimeStart']) ? $raw['checkInTimeStart'] : '',
            'check_in_end'          => isset($raw['checkInTimeEnd']) ? $raw['checkInTimeEnd'] : '',
            'check_out'             => isset($raw['checkOutTime']) ? $raw['checkOutTime'] : '',
            'active'                => (isset($raw['specialStatus']) && $raw['specialStatus'] === 'archived') ? false : true,
        );

        // Fetch Tax Settings during sync to store them locally
        $tax_response = $this->api->get_listing_tax_settings($raw['id']);
        if ( ! is_wp_error($tax_response) && isset($tax_response['status']) && $tax_response['status'] === 'success' ) {
            $mapped['tax_settings'] = $tax_response['result'];
        }

        return $mapped;
    }

    public function fetch_reviews($platform_id) {
        $response = $this->api->get_reviews($platform_id);
        if ( is_wp_error($response) ) {
            return $response;
        }

        $raw_reviews = isset($response['result']) ? $response['result'] : array();
        $standardized = array();

        foreach ($raw_reviews as $rev) {
            // Safety check: Skip if not published (even though API filter is requested)
            if ( isset($rev['status']) && $rev['status'] !== 'published' ) {
                continue;
            }

            // Convert 10-point scale to 5-star scale
            $rating = isset($rev['rating']) ? floatval($rev['rating']) : 10;
            $scaled_rating = round($rating / 2, 1);

            $standardized[] = array(
                'external_id'    => isset($rev['externalReviewId']) ? $rev['externalReviewId'] : (isset($rev['id']) ? $rev['id'] : ''),
                'author'         => !empty($rev['reviewerName']) ? $rev['reviewerName'] : (!empty($rev['guestName']) ? $rev['guestName'] : 'Guest'),
                'text'           => isset($rev['publicReview']) ? $rev['publicReview'] : '',
                'rating'         => $scaled_rating,
                'date'           => !empty($rev['submittedAt']) ? $rev['submittedAt'] : (isset($rev['insertedOn']) ? $rev['insertedOn'] : date('Y-m-d H:i:s')),
                'source'         => 'hostaway',
                'reservation_id' => isset($rev['externalReservationId']) ? $rev['externalReservationId'] : ''
            );
        }

        return $standardized;
    }

    public function fetch_availability($platform_id, $from, $to, $force_refresh = false) {
        $response = $this->api->get_calendar($platform_id, $from, $to);
        if ( is_wp_error($response) ) {
            return $response;
        }

        // Hostaway calendar structure might differ, standardizing to PBE internal format
        $days = array();
        $raw_days = isset($response['result']) ? $response['result'] : array();

        foreach ($raw_days as $day) {
            $days[] = array(
                'date'       => $day['date'],
                'status'     => (isset($day['isAvailable']) && $day['isAvailable']) ? 'available' : 'unavailable',
                'minNights'  => isset($day['minimumStay']) ? $day['minimumStay'] : 1,
                'cta'        => isset($day['closedOnArrival']) ? $day['closedOnArrival'] : '',
                'ctd'        => isset($day['closedOnDeparture']) ? $day['closedOnDeparture'] : '',
            );
        }

        return array( 'days' => $days );
    }

    public function fetch_quote($platform_id, $from, $to, $guests, $force_refresh = true) {
        $cache_key = 'pbe_hostaway_quote_' . $platform_id . '_' . $from . '_' . $to . '_' . $guests;
        $cached    = get_transient($cache_key);

        if ( ! $force_refresh && $cached !== false ) {
            return $cached;
        }

        // Get Property Meta for Markup
        $args = array(
            'post_type'  => 'property',
            'meta_query' => array(
                array('key' => 'platform_property_id', 'value' => $platform_id),
                array('key' => 'platform_source',      'value' => 'hostaway')
            ),
            'posts_per_page' => 1,
            'fields'         => 'ids'
        );
        $query = new WP_Query($args);
        $markup_multiplier = 1.0;

        if ( ! empty($query->posts) ) {
            $post_id = $query->posts[0];
            $markup_percent = get_post_meta($post_id, 'booking_engine_markup', true);
            if ( $markup_percent && is_numeric($markup_percent) ) {
                // Hostaway markups are direct multipliers (e.g. 1.07, 1.03)
                $markup_multiplier = floatval($markup_percent);
                if ( $markup_multiplier <= 0 ) {
                    $markup_multiplier = 1.0;
                }
            }
        }

        $data = array(
            'startingDate'   => $from,
            'endingDate'     => $to,
            'numberOfGuests' => intval($guests),
            'markup'         => $markup_multiplier,
            'source'         => 'website' // Try to trigger direct booking fees like 'Guest Channel Fee'
        );

        $response = $this->api->get_price_details($platform_id, $data);

        if ( is_wp_error($response) ) {
            return $response;
        }

        // Cache the quote
        $quote = $this->map_quote($response, $post_id);
        set_transient($cache_key, $quote, 15 * MINUTE_IN_SECONDS);
        
        return $quote;
    }

    /**
     * Maps Hostaway priceDetails response to internal standard
     */
    /**
     * Maps Hostaway priceDetails response to internal standard
     */
    private function map_quote($response, $post_id = 0) {
        $raw = isset($response['result']) ? $response['result'] : array();
        
        $mapped = array(
            'total'     => isset($raw['totalPrice']) ? floatval($raw['totalPrice']) : 0,
            'currency'  => isset($raw['currencyCode']) ? $raw['currencyCode'] : 'USD',
            'breakdown' => array()
        );

        $subtotal_for_fee = 0;
        $has_channel_fee  = false;

        // Calculate nights for the label
        $nights = 1;
        if ( !empty($raw['startingDate']) && !empty($raw['endingDate']) ) {
            $d1 = new DateTime($raw['startingDate']);
            $d2 = new DateTime($raw['endingDate']);
            $nights = max(1, $d1->diff($d2)->days);
        }

        if ( ! empty($raw['components']) && is_array($raw['components']) ) {
            foreach ( $raw['components'] as $item ) {
                $type  = isset($item['type']) ? $item['type'] : '';
                $name  = isset($item['name']) ? $item['name'] : '';
                $total = isset($item['total']) ? floatval($item['total']) : (isset($item['value']) ? floatval($item['value']) : 0);

                // Determine the best label and capitalize it to match native site
                $label = !empty($item['alias']) ? $item['alias'] : (!empty($item['title']) ? $item['title'] : (!empty($item['name']) ? $item['name'] : 'Fee'));
                $label = ucwords($label);
                
                // Ensure 'Base Rate' matches exactly
                if ( $name === 'baseRate' ) {
                    $label = 'Base Rate';
                }

                // Identify if Guest Channel Fee is already provided by API
                if ( strpos(strtolower($label), 'channel fee') !== false || $name === 'guestChannelFee' ) {
                    $has_channel_fee = true;
                }

                // Subtotal for the 0.5% fee calculation (includes everything EXCEPT taxes)
                if ( $type !== 'tax' ) {
                    $subtotal_for_fee += $total;
                }

                $mapped['breakdown'][] = array(
                    'label'  => $label,
                    'amount' => $total,
                    'type'   => $type,
                    'name'   => $name
                );
            }
        } else {
            // Fallback to basic components
            if ( isset($raw['basePrice']) ) {
                $val = floatval($raw['basePrice']);
                $nightly = $nights > 0 ? ($val / $nights) : $val;
                $mapped['breakdown'][] = array('label' => '$' . number_format($nightly, 2) . ' X ' . $nights . ' nights', 'amount' => $val, 'type' => 'accommodation', 'name' => 'baseRate');
                $subtotal_for_fee += $val;
            }
            if ( ! empty($raw['cleaningFee']) ) {
                $val = floatval($raw['cleaningFee']);
                $mapped['breakdown'][] = array('label' => 'Cleaning Fee', 'amount' => $val, 'type' => 'fee', 'name' => 'cleaningFee');
                $subtotal_for_fee += $val;
            }
            if ( ! empty($raw['tax']) ) {
                $mapped['breakdown'][] = array('label' => 'Taxes', 'amount' => floatval($raw['tax']), 'type' => 'tax', 'name' => 'tax');
            }
        }

        // Add Guest Channel Fee (0.5% of Subtotal) and its associated taxes
        if ( ! $has_channel_fee && $subtotal_for_fee > 0 ) {
            $fee_amount = round($subtotal_for_fee * 0.005, 2);
            if ( $fee_amount > 0 ) {
                $tax_adjustment = 0;
                
                // Apply listing-specific taxes to the fee using stored rules
                if ( $post_id ) {
                    $tax_settings = get_post_meta($post_id, 'pbe_hostaway_tax_settings', true);
                    if ( ! empty($tax_settings) && is_array($tax_settings) ) {
                        foreach ( $tax_settings as $tax_rule ) {
                            $apply_to = isset($tax_rule['applyTo']) ? (is_array($tax_rule['applyTo']) ? $tax_rule['applyTo'] : json_decode($tax_rule['applyTo'], true)) : array();
                            
                            if ( is_array($apply_to) && in_array('guestChannelFee', $apply_to) && ! empty($tax_rule['isActive']) ) {
                                $tax_val = 0;
                                if ( $tax_rule['amountType'] === 'percent' ) {
                                    $tax_val = round($fee_amount * ($tax_rule['amount'] / 100), 2);
                                }
                                
                                if ( $tax_val > 0 ) {
                                    $target_type = $tax_rule['taxType'];
                                    foreach ( $mapped['breakdown'] as &$line ) {
                                        if ( isset($line['name']) && $line['name'] === $target_type ) {
                                            $line['amount'] += $tax_val;
                                            $tax_adjustment += $tax_val;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Insert the fee
                $mapped['breakdown'][] = array(
                    'label'  => 'Guest Channel Fee',
                    'amount' => $fee_amount,
                    'type'   => 'fee',
                    'name'   => 'guestChannelFee'
                );

                // Update the total to include our calculated fee AND the extra tax
                $mapped['total'] += ($fee_amount + $tax_adjustment);
            }
        }

        return $mapped;
    }

    public function create_reservation($platform_id, $checkin, $checkout, $guests, $guest_data, $quote = array()) {
        // STRATEGY #1: MOCKING FOR HOSTAWAY
        // If Test Mode is ON in Advanced Settings, we simulate success without blocking dates.
        if ( get_option('pbe_test_mode') === '1' ) {
             error_log( "PBE DEBUG (Hostaway Mock): Reservation simulated for listing {$platform_id}. Dates: {$checkin} to {$checkout}. Guest: {$guest_data['first_name']} {$guest_data['last_name']}" );
             
             return array(
                 'id'     => 'MOCK_HOSTAWAY_' . time(),
                 'status' => 'new',
                 'source' => 'website_mock'
             );
        }

        $body = array(
            'listingMapId'   => $platform_id,
            'arrivalDate'    => $checkin,
            'departureDate'  => $checkout,
            'guestName'      => $guest_data['first_name'] . ' ' . $guest_data['last_name'],
            'guestFirstName' => $guest_data['first_name'],
            'guestLastName'  => $guest_data['last_name'],
            'guestEmail'     => $guest_data['email'],
            'phone'          => $guest_data['phone'],
            'numberOfGuests' => $guests,
            'status'         => 'new',
            'channelId'      => 2000 // this is Hostaway's ID for Direct/Website bookings
        );

        return $this->api->create_reservation($body);
    }

    public function purge_quote_cache($platform_id) {
        global $wpdb;
        $prefix = '_transient_pbe_hostaway_quote_' . $platform_id . '_';
        $keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
        
        if ( ! empty($keys) ) {
            foreach ( $keys as $key ) {
                $transient = str_replace( '_transient_', '', $key );
                delete_transient( $transient );
            }
        }
    }
}
