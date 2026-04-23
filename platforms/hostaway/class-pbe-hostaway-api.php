<?php
/**
 * Hostaway API Wrapper
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Hostaway_API {

    private $api_key;
    private $account_id;
    private $base_url = 'https://api.hostaway.com/v1';
    private $access_token = '';

    public function __construct($api_key = '', $account_id = '', $manual_token = '') {
        $this->api_key = $api_key;
        $this->account_id = $account_id;
        $this->access_token = $manual_token;
    }

    /**
     * Internal request handler with built-in retry logic
     */
    private function request($method, $endpoint, $body = array(), $attempt = 1, $is_auth_request = false) {
        $url = $this->base_url . $endpoint;

        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
            )
        );

        if ( ! $is_auth_request ) {
            // Only attempt auto-auth if we don't already have a token (manual or cached)
            if ( empty($this->access_token) ) {
                $cached_token = get_transient('pbe_hostaway_access_token');
                if ( $cached_token ) {
                    $this->access_token = $cached_token;
                } else {
                    $auth_result = $this->authenticate();
                    if ( is_wp_error($auth_result) ) {
                        return $auth_result;
                    }
                }
            }
            $args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
        }

        if ( ! empty( $body ) ) {
            if ( $is_auth_request ) {
                $args['body'] = $body; // application/x-www-form-urlencoded
            } else {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body'] = wp_json_encode($body);
            }
        }

        $response = wp_remote_request($url, $args);
        
        if ( is_wp_error($response) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $res_body    = wp_remote_retrieve_body($response);
        $decoded     = json_decode($res_body, true);

        // Handle Rate Limiting (429)
        if ( $status_code === 429 ) {
            if ( $attempt <= 3 ) {
                sleep(2);
                return $this->request($method, $endpoint, $body, $attempt + 1, $is_auth_request);
            }
            return new WP_Error('rate_limit_exceeded', 'Hostaway API rate limit exceeded.');
        }

        // Handle Auth Expiry (401)
        if ( $status_code === 401 && ! $is_auth_request && $attempt === 1 ) {
            delete_transient('pbe_hostaway_access_token');
            $this->access_token = '';
            
            // Only retry if we have the credentials to get a new token
            if ( ! empty($this->api_key) && ! empty($this->account_id) ) {
                return $this->request($method, $endpoint, $body, 2, false);
            }
            
            return new WP_Error('auth_failed', 'Hostaway token expired and no API Key/Account ID provided for renewal.');
        }

        if ( $status_code >= 400 ) {
            $msg = isset($decoded['message']) ? $decoded['message'] : 'Hostaway API Error ' . $status_code;
            return new WP_Error('api_error', $msg);
        }

        return $decoded;
    }

    /**
     * Authenticate and cache token
     */
    public function authenticate() {
        if ( ! empty($this->access_token) || get_transient('pbe_hostaway_access_token') !== false ) {
            return true;
        }
        
        $body = array(
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->account_id,
            'client_secret' => $this->api_key,
            'scope'         => 'general'
        );
        
        $response = $this->request('POST', '/accessTokens', $body, 1, true);

        if ( is_wp_error($response) ) {
            return $response;
        }

        if ( ! empty($response['access_token']) ) {
            $this->access_token = $response['access_token'];
            $expires_in = isset($response['expires_in']) ? intval($response['expires_in']) : 86400;
            set_transient('pbe_hostaway_access_token', $this->access_token, $expires_in - 300);
            return true;
        }
        
        return new WP_Error('auth_failed', 'Failed to retrieve access token from Hostaway.');
    }

    public function get_listings($limit = 50, $offset = 0) {
        $endpoint = '/listings?limit=' . $limit . '&offset=' . $offset;
        $response = $this->request('GET', $endpoint);
        
        if ( is_wp_error($response) ) {
            return $response; 
        }
        
        return isset($response['result']) ? $response['result'] : array();
    }

    public function get_listing($listing_id) {
        return $this->request('GET', '/listings/' . $listing_id);
    }

    public function get_calendar($listing_id, $start_date, $end_date) {
        $endpoint = "/listings/{$listing_id}/calendar?startDate={$start_date}&endDate={$end_date}";
        return $this->request('GET', $endpoint);
    }

    public function get_reviews($listing_id) {
        $endpoint = "/reviews?listingMapIds[0]={$listing_id}&type=guest-to-host&statuses[0]=published";
        return $this->request('GET', $endpoint);
    }

    public function get_property_types() {
        return $this->request('GET', '/propertyTypes');
    }

    public function get_amenities() {
        return $this->request('GET', '/amenities');
    }

    public function create_reservation($body) {
        return $this->request('POST', '/reservations', $body);
    }

    public function get_price_details($listing_id, $data) {
        $endpoint = "/listings/{$listing_id}/calendar/priceDetails";
        return $this->request('POST', $endpoint, $data);
    }

    public function get_listing_tax_settings($listing_id) {
        $endpoint = "/listingTaxSettings/{$listing_id}";
        return $this->request('GET', $endpoint);
    }
}
