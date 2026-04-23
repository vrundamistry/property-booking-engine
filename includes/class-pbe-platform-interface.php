<?php
/**
 * Platform Interface for Vacation Rental APIs
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface PBE_Platform_Interface {

    /**
     * Authenticate or prepare API credentials
     *
     * @return bool
     */
    public function authenticate();

    /**
     * Fetch raw properties from the platform
     *
     * @param int $limit Max properties to fetch per request
     * @param int $skip Offset for pagination
     * @return array array of raw property objects, or an array with 'properties' and 'total' if available
     */
    public function fetch_properties( $limit = 50, $skip = 0 );

    /**
     * Fetch a single property from the platform.
     *
     * @param string $platform_id
     * @return array|WP_Error Standardized property data
     */
    public function fetch_single_property( $platform_id );

    /**
     * Map a raw platform property object to the standardized plugin format.
     * 
     * Output must include:
     * - name, description, price, bedrooms, bathrooms, max_guests, 
     *   lat, lng, amenities (array), images (array), platform_id
     *
     * @param mixed $raw_property
     * @return array Standardized property data
     */
    public function map_property( $raw_property );

    /**
     * Fetch and Map Reviews for a specific listing
     *
     * @param string $platform_id
     * @return array Array of standardized review data
     */
    public function fetch_reviews( $platform_id );

    /**
     * Fetch a quote for a specific stay
     * 
     * @param string $platform_id
     * @param string $from YYYY-MM-DD
     * @param string $to YYYY-MM-DD
     * @param int $guests
     * @param bool $force_refresh Bypass cache
     * @return array|WP_Error Standardized quote breakdown
     */
    public function fetch_quote( $platform_id, $from, $to, $guests, $force_refresh = false );

    /**
     * Fetch real-time availability calendar
     * 
     * @param string $platform_id
     * @param string $from YYYY-MM-DD
     * @param string $to YYYY-MM-DD
     * @param bool $force_refresh Bypass cache
     * @return array|WP_Error Standardized availability data
     */
    public function fetch_availability( $platform_id, $from, $to, $force_refresh = false );
    /**
     * Create a new reservation on the platform
     * 
     * @param string $platform_id
     * @param string $checkin YYYY-MM-DD
     * @param string $checkout YYYY-MM-DD
     * @param int $guests
     * @param array $guest_data ['first_name', 'last_name', 'email', 'phone', 'comments']
     * @param array $quote Optional standardized quote data from fetch_quote
     * @return array|WP_Error Standardized reservation response
     */
    public function create_reservation( $platform_id, $checkin, $checkout, $guests, $guest_data, $quote = array() );

    /**
     * Purge the cached quotes for a specific listing
     * 
     * @param string $platform_id
     * @return void
     */
    public function purge_quote_cache( $platform_id );

}
