<?php
/**
 * PBE Filter Helper
 *
 * Handles the mapping of high-level "Features" to actual amenity terms.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Filter_Helper {

    /**
     * Get the list of supported features and their mappings.
     */
    public static function get_feature_mappings() {
        return array(
            'pool' => array(
                'label'    => 'Swimming Pool',
                'keywords' => array( 'Pool', 'Private Pool', 'Swimming Pool', 'Heated Pool', 'Outdoor Pool' )
            ),
            'pets' => array(
                'label'    => 'Pet Friendly',
                'keywords' => array( 'Pets Allowed', 'Pet Friendly', 'Dogs Welcome', 'Pets Welcome' )
            ),
            'waterfront' => array(
                'label'    => 'Waterfront',
                'keywords' => array( 'Beachfront', 'Oceanfront', 'Waterfront', 'Lake Access', 'River View' )
            ),
            'wifi' => array(
                'label'    => 'Internet Wifi',
                'keywords' => array( 'Wifi', 'Internet', 'Wireless Internet', 'High Speed Internet' )
            ),
            'parking' => array(
                'label'    => 'Free Parking',
                'keywords' => array( 'Free Parking', 'Parking', 'Garage', 'Covered Parking' )
            ),
            'ac' => array(
                'label'    => 'Air Conditioning',
                'keywords' => array( 'Air Conditioning', 'AC', 'Central Air', 'A/C' )
            ),
        );
    }

    /**
     * Get the slugs for the mappings.
     */
    public static function get_feature_slugs() {
        return array_keys( self::get_feature_mappings() );
    }

    /**
     * Get keywords for a specific feature.
     */
    public static function get_keywords_for_feature( $slug ) {
        $mappings = self::get_feature_mappings();
        return isset( $mappings[ $slug ] ) ? $mappings[ $slug ]['keywords'] : array();
    }
}
