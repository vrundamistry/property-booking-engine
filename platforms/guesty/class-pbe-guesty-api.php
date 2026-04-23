<?php
/**
 * Guesty API Wrapper
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Guesty_API {

    private $api_key;
    private $api_secret;
    private $account_id;
    private $base_url = 'https://open-api.guesty.com/v1';
    private $access_token = '';

    public function __construct($key = '', $secret = '', $account_id = '', $api_endpoint = '') {
        $this->api_key = $key;
        $this->api_secret = $secret;
        $this->account_id = $account_id;
        
        if ( ! empty( $api_endpoint ) ) {
            $this->base_url = rtrim( $api_endpoint, '/' );
        }
    }

    /**
     * Internal request handler with built-in retry logic for 429 and 401 caching recoveries
     * 
     * @param string $method HTTP Method (GET, POST, etc.)
     * @param string $endpoint The API relative endpoint (e.g. '/listings')
     * @param array $body Optional request body for POST/PUT
     * @param int $attempt Current attempt counter for 429 retries
     * @param bool $is_auth_request Flag to omit standard headers during auth
     * @return mixed Array on success, WP_Error on failure
     */
    private function request($method, $endpoint, $body = array(), $attempt = 1, $is_auth_request = false) {
        if ( $is_auth_request ) {
            $url = str_replace( '/v1', '', $this->base_url ) . $endpoint;
        } else {
            $url = $this->base_url . $endpoint;
        }

        
        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
            )
        );

        if ( ! $is_auth_request ) {
            // Check Transient Cache before generating token
            $cached_token = get_transient('pbe_guesty_access_token');
            if ( $cached_token ) {
                $this->access_token = $cached_token;
            } else {
                $auth_result = $this->authenticate();
                if ( is_wp_error($auth_result) ) {
                    return $auth_result;
                }
            }
            $args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
        }

        if ( ! empty( $this->account_id ) ) {
            $args['headers']['x-guesty-account-id'] = $this->account_id;
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
                sleep(2); // Wait 2 seconds before retrying
                return $this->request($method, $endpoint, $body, $attempt + 1, $is_auth_request);
            }
            return new WP_Error('rate_limit_exceeded', 'Guesty API rate limit exceeded after 3 retries.');
        }

        // Handle Auth/Token Expiry Errors (401/403)
        if ( in_array($status_code, array(401, 403)) ) {
            if ( ! $is_auth_request && $attempt === 1 ) {
                // Token likely expired before transient cleared or revoked upstream.
                // Clear cache and try exactly ONE more time.
                delete_transient('pbe_guesty_access_token');
                $this->access_token = '';
                return $this->request($method, $endpoint, $body, 2, false);
            }
            
            return new WP_Error('auth_failed', 'Guesty API returned ' . $status_code . ': Unauthorized or Forbidden.');
        }

        // Handle other HTTP errors
        if ( $status_code >= 400 ) {
            $msg = $this->get_error_message_from_response($decoded, $status_code);
            $debug_info = "PBE Guesty API Error ($status_code): $msg | URL: $url | Body: $res_body | Args: " . print_r($args, true);
            file_put_contents(WP_CONTENT_DIR . '/guesty_error.txt', $debug_info);
            error_log( $debug_info );
            return new WP_Error('api_error', $msg);
        }

        return $decoded;
    }

    /**
     * Authenticate, retrieve OAuth Access Token, and Cache it
     * 
     * @return bool|WP_Error
     */
    public function authenticate() {
        // If we already have it in memory or cache, we can skip
        if ( ! empty($this->access_token) || get_transient('pbe_guesty_access_token') !== false ) {
            return true;
        }
        
        $body = array(
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->api_key,
            'client_secret' => $this->api_secret,
        );
        
        // Pass to internal request method marking as auth request
        $response = $this->request('POST', '/oauth2/token', $body, 1, true);

        if ( is_wp_error($response) ) {
            return $response;
        }

        if ( ! empty($response['access_token']) ) {
            $this->access_token = $response['access_token'];
            
            // Default 12 hours caching buffer if not provided
            $expires_in = isset($response['expires_in']) ? intval($response['expires_in']) : 43200;
            
            // Subtract 5 minutes safety buffer, but never go below 60 seconds
            $cache_ttl = max(60, $expires_in - 300);
            
            set_transient('pbe_guesty_access_token', $this->access_token, $cache_ttl);
            return true;
        }
        
        return new WP_Error('auth_failed', 'Failed to retrieve access token from Guesty. Check credentials.');
    }

    /**
     * Fetch Listings from Guesty using Pagination (limit + skip)
     * 
     * @param int $limit
     * @param int $skip
     * @return array|WP_Error Combined array of properties
     */
    public function get_listings($limit = 50, $skip = 0) {
        $endpoint = '/listings?limit=' . $limit . '&skip=' . $skip;
        $response = $this->request('GET', $endpoint);
        
        if ( is_wp_error($response) ) {
            return $response; 
        }
        
        return isset($response['results']) ? $response['results'] : array();
    }

    /**
     * Fetch a Single Listing Detail
     * 
     * @param string $listing_id
     * @return array|WP_Error
     */
    public function get_listing($listing_id) {
        return $this->request('GET', '/listings/' . $listing_id);
    }

    /**
     * Fetch Availability (Stub)
     * 
     * @param string $listing_id
     */
    public function get_availability($listing_id, $start_date = '', $end_date = '') {
        $endpoint = '/availability-pricing/api/calendar/listings/' . $listing_id;
        
        $params = array();
        if ( ! empty($start_date) ) $params['startDate'] = $start_date;
        if ( ! empty($end_date) )   $params['endDate'] = $end_date;

        if ( ! empty($params) ) {
            $endpoint = add_query_arg( $params, $endpoint );
        }

        return $this->request('GET', $endpoint);
    }

    /**
     * Fetch Rates (Stub)
     * 
     * @param string $listing_id
     */
    public function get_rates($listing_id) {
        // Typically merged with availability calendar in Guesty
        return array();
    }

    /**
     * Fetch Reservations (Stub)
     * 
     * @param string $listing_id
     */
    public function get_reservations($listing_id) {
        $endpoint = '/reservations?listingId=' . $listing_id;
        return $this->request('GET', $endpoint);
    }

    /**
     * Fetch a Reservation by Confirmation Code
     * 
     * @param string $code
     * @return array|WP_Error
     */
    public function get_reservation_by_confirmation_code($code) {
        $endpoint = '/reservations?confirmationCode=' . $code;
        return $this->request('GET', $endpoint);
    }

    /**
     * Fetch Supported Amenities & Groups
     * 
     * @return array|WP_Error Supported amenities dictionary
     */
    public function get_supported_amenities() {
        return $this->request('GET', '/properties-api/amenities/supported');
    }

    /**
     * Fetch Reviews for a specific listing
     * 
     * @param string $listing_id
     * @return array|WP_Error
     */
    public function get_reviews($listing_id) {
        $endpoint = '/reviews?listingId=' . $listing_id . '&limit=100';
        return $this->request('GET', $endpoint);
    }

    /**
     * Fetch a Reservation Quote
     * 
     * @param string $listing_id
     * @param string $checkin YYYY-MM-DD
     * @param string $checkout YYYY-MM-DD
     * @param int $guests
     * @return array|WP_Error
     */
    public function get_quote($listing_id, $checkin, $checkout, $guests) {
        $body = array(
            'listingId'              => $listing_id,
            'checkInDateLocalized'   => $checkin,
            'checkOutDateLocalized'  => $checkout,
            'guestsCount'            => intval($guests),
            'source'                 => 'website'
        );

        return $this->request('POST', '/quotes', $body);
    }

    /**
     * Create a new reservation on Guesty
     * 
     * @param string $listing_id
     * @param string $checkin YYYY-MM-DD
     * @param string $checkout YYYY-MM-DD
     * @param int $guests
     * @param array $guest_data ['first_name', 'last_name', 'email', 'phone', 'comments']
     * @return array|WP_Error
     */
    public function create_reservation($listing_id, $checkin, $checkout, $guests, $guest_data, $quote = array()) {
        $body = array(
            'listingId'              => $listing_id,
            'checkInDateLocalized'   => $checkin,
            'checkOutDateLocalized'  => $checkout,
            'guestsCount'            => intval($guests),
            'status'                 => 'reserved', // Request to Book (Pending)
            'source'                 => 'website',
            'guest'                  => array( // Should be 'guest' not 'contact'
                'firstName' => $guest_data['first_name'],
                'lastName'  => $guest_data['last_name'],
                'email'     => $guest_data['email'],
                'phone'     => $guest_data['phone']
            )
        );

        // Special requests (notes) removed as they require a specific object/array format 
        // and currently cause 400 errors when sent as a simple string.

        return $this->request('POST', '/reservations', $body);
    }

    /**
     * Cancel an existing reservation on Guesty
     * 
     * @param string $reservation_id
     * @return array|WP_Error
     */
    public function cancel_reservation($reservation_id) {
        $body = array(
            'status' => 'canceled'
        );
        return $this->request('PUT', '/reservations/' . $reservation_id, $body);
    }

    /**
     * Delete a calendar block on Guesty
     * 
     * @param string $listing_id
     * @param string $block_id
     * @return array|WP_Error
     */
    public function delete_block($listing_id, $block_id) {
        return $this->request('DELETE', '/availability-pricing/api/calendar/listings/' . $listing_id . '/blocks/' . $block_id);
    }

    /**
     * Update Availability for specific days on Guesty
     * 
     * @param string $listing_id
     * @param array $days_data Array of ['date' => 'YYYY-MM-DD', 'status' => 'available'|'blocked']
     * @return array|WP_Error
     */
    public function update_availability($listing_id, $days_data) {
        $body = array( 'days' => $days_data );
        return $this->request('PUT', '/availability-pricing/api/calendar/listings/' . $listing_id . '/days', $body);
    }

    /**
     * Helper to extract a readable error message from Guesty response
     */
    private function get_error_message_from_response($decoded, $status_code) {
        if ( isset($decoded['error']['data']) && is_array($decoded['error']['data']) ) {
            return implode(', ', $decoded['error']['data']);
        }
        if ( isset($decoded['error']['message']) ) {
            return $decoded['error']['message'];
        }
        if ( isset($decoded['message']) ) {
            return $decoded['message'];
        }
        return 'Guesty API Error ' . $status_code;
    }
}
