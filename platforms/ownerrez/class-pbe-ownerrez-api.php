<?php
/**
 * OwnerRez API Wrapper
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_OwnerRez_API {

    private $basic_auth;
    private $base_url = 'https://api.ownerrez.com/v2';

    public function __construct( $basic_auth ) {
        $this->basic_auth = $basic_auth;
    }

    private function get_headers() {
        $token = trim( $this->basic_auth );
        
        if ( stripos($token, 'Basic ') === 0 || stripos($token, 'Bearer ') === 0 ) {
            $auth_header = $token;
        } else {
            // OwnerRez API v2 supports Bearer tokens
            $auth_header = 'Bearer ' . $token;
        }
        
        return array(
            'Authorization' => $auth_header,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'cache-control' => 'no-cache',
            'pragma'        => 'no-cache',
            'user-agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.163 Safari/537.36',
        );
    }

    private function request( $method, $endpoint, $body = array(), $query_args = array() ) {
        $url = $this->base_url . $endpoint;
        if ( ! empty( $query_args ) ) {
            $url = add_query_arg( $query_args, $url );
        }

        $args = array(
            'method'  => $method,
            'headers' => $this->get_headers(),
            'timeout' => 30
        );

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $res_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $res_body, true );
        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code >= 400 ) {
            return new WP_Error( 'ownerrez_api_error', isset($data['messages']) ? implode(', ', $data['messages']) : 'API Error ' . $status_code, $data );
        }

        return $data;
    }

    public function request_url( $method, $url ) {
        $args = array(
            'method'  => $method,
            'headers' => $this->get_headers(),
            'timeout' => 30
        );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $res_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $res_body, true );
        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code >= 400 ) {
            return new WP_Error( 'ownerrez_api_error', isset($data['messages']) ? implode(', ', $data['messages']) : 'API Error ' . $status_code, $data );
        }

        return $data;
    }

    public function get_properties( $limit = 50, $offset = 0 ) {
        return $this->request( 'GET', '/properties', array(), array(
            'limit' => $limit,
            'offset' => $offset
        ) );
    }

    public function get_property( $property_id ) {
        return $this->request( 'GET', '/properties/' . $property_id );
    }

    public function get_listings( $limit = 50, $offset = 0 ) {
        return $this->request( 'GET', '/listings', array(), array(
            'limit' => $limit,
            'offset' => $offset,
            'includeAmenities' => 'true',
            'includeRooms' => 'true',
            'includeBathrooms' => 'true',
            'includeImages' => 'true',
            'includeDescriptions' => 'html',
        ) );
    }

    public function get_listing( $property_id ) {
        return $this->request( 'GET', '/listings/' . $property_id, array(), array(
            'includeAmenities' => 'true',
            'includeRooms' => 'true',
            'includeBathrooms' => 'true',
            'includeImages' => 'true',
            'includeDescriptions' => 'html',
        ) );
    }

    public function get_reviews( $property_id, $limit = 100, $offset = 0 ) {
        return $this->request( 'GET', '/reviews', array(), array(
            'property_id'   => $property_id,
            'limit'         => $limit,
            'offset'        => $offset,
            'active'        => 'true',
            'host_review'   => 'false',
            'include_guest' => 'true'
        ) );
    }

    public function get_bookings( $property_id, $limit = 100, $offset = 0 ) {
        return $this->request( 'GET', '/bookings', array(), array(
            'property_ids'  => $property_id,
            'status'        => 'active',
            'limit'         => $limit,
            'offset'        => $offset
        ) );
    }
}
