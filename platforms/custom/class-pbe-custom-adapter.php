<?php
/**
 * Custom Platform Adapter
 * Handles quotes, availability, and bookings locally from the database.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Custom_Adapter implements PBE_Platform_Interface {

    public function authenticate() {
        return true;
    }

    public function fetch_properties( $limit = 50, $skip = 0 ) {
        return array();
    }

    public function fetch_single_property( $platform_id ) {
        global $wpdb;
        $post_id = $wpdb->get_var( $wpdb->prepare( "
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = 'platform_property_id' 
            AND meta_value = %s 
            LIMIT 1
        ", $platform_id ) );

        if ( ! $post_id ) {
            return new WP_Error( 'not_found', 'Property not found.' );
        }

        return $this->map_property( array(
            'platform_id' => $platform_id,
            'post_id' => $post_id
        ) );
    }

    public function map_property( $raw ) {
        $post_id = $raw['post_id'];
        return array(
            'platform_id'      => $raw['platform_id'],
            'platform_source'  => 'custom',
            'name'             => get_the_title( $post_id ),
            'description'      => get_post_field( 'post_content', $post_id ),
            'price'            => floatval( get_post_meta( $post_id, 'price_per_night', true ) ),
            'bedrooms'         => intval( get_post_meta( $post_id, 'bedrooms', true ) ),
            'bathrooms'        => floatval( get_post_meta( $post_id, 'bathrooms', true ) ),
            'max_guests'       => intval( get_post_meta( $post_id, 'max_guests', true ) ),
            'min_nights'       => intval( get_post_meta( $post_id, 'min_nights', true ) ),
            'active'           => get_post_meta( $post_id, 'is_active', true ) === '1',
            'is_listed'        => get_post_meta( $post_id, 'is_listed', true ) === '1',
            'currency'         => get_post_meta( $post_id, 'currency', true ) ?: 'USD',
        );
    }

    public function fetch_reviews( $platform_id ) {
        // Query database for reviews of this custom property
        global $wpdb;
        $post_id = $wpdb->get_var( $wpdb->prepare( "
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = 'platform_property_id' 
            AND meta_value = %s 
            LIMIT 1
        ", $platform_id ) );

        if ( ! $post_id ) {
            return array();
        }

        $reviews_query = new WP_Query( array(
            'post_type'      => 'pbe_review',
            'post_status'    => 'publish',
            'post_parent'    => $post_id,
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => 'pbe_platform_source',
                    'value'   => 'custom'
                )
            )
        ) );

        $reviews = array();
        if ( ! empty( $reviews_query->posts ) ) {
            foreach ( $reviews_query->posts as $rev ) {
                $reviews[] = array(
                    'external_id'    => get_post_meta( $rev->ID, 'pbe_review_id', true ),
                    'author'         => $rev->post_title,
                    'text'           => $rev->post_content,
                    'date'           => $rev->post_date,
                    'rating'         => floatval( get_post_meta( $rev->ID, 'pbe_rating', true ) ),
                    'source'         => get_post_meta( $rev->ID, 'pbe_source', true ) ?: 'custom',
                    'title'          => get_post_meta( $rev->ID, 'pbe_review_title', true ),
                    'reservation_id' => get_post_meta( $rev->ID, 'pbe_platform_res_id', true ),
                    'listing_site'   => get_post_meta( $rev->ID, 'pbe_listing_site', true ),
                );
            }
        }

        return $reviews;
    }

    /**
     * Calculate stay quote locally using the database table
     */
    public function fetch_quote( $platform_id, $from, $to, $guests, $force_refresh = false ) {
        $cache_key = 'pbe_quote_' . $platform_id . '_' . $from . '_' . $to . '_' . $guests;
        $cached    = get_transient( $cache_key );

        if ( ! $force_refresh && $cached !== false ) {
            return $cached;
        }

        global $wpdb;
        $post_id = $wpdb->get_var( $wpdb->prepare( "
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = 'platform_property_id' 
            AND meta_value = %s 
            LIMIT 1
        ", $platform_id ) );

        if ( ! $post_id ) {
            return new WP_Error( 'not_found', 'Property not found.' );
        }

        $base_price     = floatval( get_post_meta( $post_id, 'price_per_night', true ) );
        $cleaning_fee  = floatval( get_post_meta( $post_id, 'cleaning_fee', true ) );
        $damage_deposit = floatval( get_post_meta( $post_id, 'damage_deposit', true ) );
        $currency       = get_post_meta( $post_id, 'currency', true ) ?: 'USD';

        // Load custom dates pricing from database
        $table_name = $wpdb->prefix . 'pbe_calendar_dates';
        $db_dates = $wpdb->get_results( $wpdb->prepare( "
            SELECT calendar_date, price FROM $table_name 
            WHERE platform_property_id = %s 
            AND calendar_date >= %s 
            AND calendar_date < %s
        ", $platform_id, $from, $to ), OBJECT_K );

        // Loop over each day of stay to calculate rates
        $start_date = new DateTime( $from );
        $end_date   = new DateTime( $to );
        $interval   = new DateInterval( 'P1D' );
        $period     = new DatePeriod( $start_date, $interval, $end_date );

        $nightly_sum = 0.0;
        $nights_count = 0;

        foreach ( $period as $date ) {
            $date_str = $date->format( 'Y-m-d' );
            $daily_price = $base_price;

            if ( isset( $db_dates[ $date_str ] ) && ! empty( $db_dates[ $date_str ]->price ) ) {
                $daily_price = floatval( $db_dates[ $date_str ]->price );
            }

            $nightly_sum += $daily_price;
            $nights_count++;
        }

        if ( $nights_count === 0 ) {
            return new WP_Error( 'invalid_dates', 'Invalid date range selected.' );
        }

        $total_price = $nightly_sum + $cleaning_fee + $damage_deposit;

        $mapped = array(
            'total'     => floatval( $total_price ),
            'net'       => floatval( $nightly_sum ),
            'currency'  => $currency,
            'breakdown' => array(
                array(
                    'label'  => sprintf( _n( 'Nightly Stay (%d Night)', 'Nightly Stay (%d Nights)', $nights_count, 'property-booking-engine' ), $nights_count ),
                    'amount' => floatval( $nightly_sum )
                )
            )
        );

        if ( $cleaning_fee > 0 ) {
            $mapped['breakdown'][] = array(
                'label'  => __( 'Cleaning Fee', 'property-booking-engine' ),
                'amount' => floatval( $cleaning_fee )
            );
        }

        if ( $damage_deposit > 0 ) {
            $mapped['breakdown'][] = array(
                'label'  => __( 'Damage Deposit', 'property-booking-engine' ),
                'amount' => floatval( $damage_deposit )
            );
        }

        // Cache for 15 minutes (or whatever config says)
        $duration = intval( get_option( 'pbe_cache_duration', 15 ) ) * MINUTE_IN_SECONDS;
        if ( $duration > 0 ) {
            set_transient( $cache_key, $mapped, $duration );
        }

        return $mapped;
    }

    /**
     * Retrieve local calendar availability data
     */
    public function fetch_availability( $platform_id, $from, $to, $force_refresh = false ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pbe_calendar_dates';
        $results = $wpdb->get_results( $wpdb->prepare( "
            SELECT calendar_date AS date, status, min_nights, guests, cta, ctd, price FROM $table_name 
            WHERE platform_property_id = %s 
            AND calendar_date >= %s 
            AND calendar_date <= %s
        ", $platform_id, $from, $to ), ARRAY_A );

        // Standardize output format
        return array( 'days' => $results );
    }

    /**
     * Handle local reservation confirmations
     */
    public function create_reservation( $platform_id, $checkin, $checkout, $guests, $guest_data, $quote = array() ) {
        // Since it's a custom platform, we confirm it locally.
        // We can return a custom confirmation ID.
        $confirm_id = 'CUST-' . strtoupper( wp_generate_password( 8, false ) );
        return array(
            'id' => $confirm_id,
            'status' => 'confirmed'
        );
    }

    /**
     * Clear cached transients
     */
    public function purge_quote_cache( $platform_id ) {
        global $wpdb;
        $prefix = '_transient_pbe_quote_' . $platform_id . '_';
        $keys = $wpdb->get_col( $wpdb->prepare( "
            SELECT option_name FROM {$wpdb->options} 
            WHERE option_name LIKE %s
        ", $wpdb->esc_like( $prefix ) . '%' ) );

        if ( ! empty( $keys ) ) {
            foreach ( $keys as $key ) {
                $transient = str_replace( '_transient_', '', $key );
                delete_transient( $transient );
            }
        }
    }
}
