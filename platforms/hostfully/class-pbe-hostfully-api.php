<?php
/**
 * Hostfully API Wrapper
 * Handles all REST requests to the Hostfully API (v3.2)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Hostfully_API {

    private $api_key;
    private $base_url = 'https://api.hostfully.com/api/v3.2/';

    public function __construct( $api_key ) {
        $this->api_key = $api_key;
    }

    /**
     * Authenticate (Simple check if key is set)
     */
    public function authenticate() {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'missing_api_key', 'Hostfully API Key is required.' );
        }
        return true;
    }

    /**
     * Send a request to Hostfully API
     */
    private function request( $endpoint, $params = array(), $method = 'GET' ) {
        $url = $this->base_url . $endpoint;
        
        if ( $method === 'GET' && ! empty( $params ) ) {
            $query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
            $url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . $query;
        }

        $args = array(
            'method'  => $method,
            'headers' => array(
                'X-HOSTFULLY-APIKEY' => $this->api_key,
                'Accept'             => 'application/json',
                'Content-Type'       => 'application/json',
                'Postman-Token'      => 'PBE-' . uniqid(),
            ),
            'timeout' => 30,
        );

        if ( $method === 'POST' || $method === 'PUT' ) {
            $args['body'] = wp_json_encode( $params );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 ) {
            $err_msg = isset($data['message']) ? $data['message'] : (isset($data['error']) ? (is_string($data['error']) ? $data['error'] : json_encode($data['error'])) : 'API Error ' . $code . ': ' . $body);
            
            // Log for debugging
            error_log( "PBE Hostfully API Error ($code) on URL: $url" );
            error_log( "PBE Hostfully API Response Body: $body" );
            
            return new WP_Error( 'api_error', $err_msg, $data );
        }

        return $data;
    }

    /**
     * Get listings for an agency
     */
    public function get_listings( $agency_uid, $limit = 50, $cursor = '' ) {
        $params = array(
            'agencyUid' => $agency_uid,
            '_limit'    => $limit,
        );

        if ( ! empty( $cursor ) ) {
            error_log( "PBE DEBUG: Original Cursor: " . $cursor );
            // Ensure base64 padding is correct. Hostfully v3.2 is sensitive to missing '='.
            $cursor = rtrim($cursor, '='); 
            $padding = strlen($cursor) % 4;
            if ( $padding > 0 ) {
                $cursor .= str_repeat('=', 4 - $padding);
            }
            error_log( "PBE DEBUG: Padded Cursor: " . $cursor );
            $params['_cursor'] = $cursor;
        }

        return $this->request( 'properties', $params );
    }

    /**
     * Get single property details
     */
    public function get_property( $uid, $agency_uid = '' ) {
        $params = array();
        if ( ! empty( $agency_uid ) ) {
            $params['agencyUid'] = $agency_uid;
        }
        return $this->request( 'properties/' . $uid, $params );
    }

    /**
     * Get property photos
     */
    public function get_photos( $uid ) {
        return $this->request( 'photos', array( 'propertyUid' => $uid ) );
    }

    /**
     * Get property amenities
     */
    public function get_amenities( $uid ) {
        return $this->request( 'amenities', array( 'propertyUid' => $uid ) );
    }

    /**
     * Get property descriptions
     */
    public function get_property_descriptions( $uid ) {
        return $this->request( 'property-descriptions', array( 'propertyUid' => $uid ) );
    }

    /**
     * Get calendar availability
     */
    public function get_calendar( $uid, $from, $to ) {
        // v3.2 property-calendar endpoint
        return $this->request( 'property-calendar/' . $uid, array(
            'from' => $from,
            'to'   => $to
        ) );
    }

    /**
     * Get property reviews
     */
    public function get_reviews( $uid, $cursor = '' ) {
        $params = array( 'propertyUid' => $uid );
        if ( ! empty( $cursor ) ) {
            // Apply same padding logic as properties
            $cursor = rtrim($cursor, '='); 
            $padding = strlen($cursor) % 4;
            if ( $padding > 0 ) {
                $cursor .= str_repeat('=', 4 - $padding);
            }
            $params['_cursor'] = $cursor;
        }
        return $this->request( 'reviews', $params );
    }

    /**
     * Get a quote for a property
     */
    public function get_quote( $agency_uid, $property_uid, $check_in, $check_out, $guests ) {
        return $this->request( 'quotes', array(
            'agencyUid'    => $agency_uid,
            'propertyUid'  => $property_uid,
            'checkInDate'  => $check_in,
            'checkOutDate' => $check_out,
            'guests'       => $guests
        ), 'POST' );
    }

    /**
     * Create a lead (inquiry/booking attempt)
     */
    public function create_lead( $agency_uid, $property_uid, $check_in, $check_out, $guests, $guest_data = array() ) {
        $params = array(
            'agencyUid'         => $agency_uid,
            'propertyUid'       => $property_uid,
            'checkInLocalDate'  => $check_in,
            'checkOutLocalDate' => $check_out,
            'guests'            => intval( $guests ),
            'status'            => 'NEW',
            'type'              => 'INQUIRY',
            'guestInformation'  => array(
                'firstName'   => !empty($guest_data['first_name']) ? $guest_data['first_name'] : 'Pending',
                'lastName'    => !empty($guest_data['last_name']) ? $guest_data['last_name'] : 'Guest',
                'email'       => !empty($guest_data['email']) ? $guest_data['email'] : 'pending@guest.com',
                'phoneNumber' => !empty($guest_data['phone']) ? $guest_data['phone'] : null,
            )
        );

        return $this->request( 'leads', $params, 'POST' );
    }

    /**
     * Transform an inquiry/lead to a confirmed booking
     * POST /leads/{leadUid}/mark-as-booked
     */
    public function mark_as_booked( $lead_uid ) {
        return $this->request( "leads/{$lead_uid}/mark-as-booked", array(), 'POST' );
    }
}
