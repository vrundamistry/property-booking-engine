<?php
/**
 * Main Importer Logic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Importer {

    public function __construct() {
        // New dynamic schedule hook
        add_action('pbe_auto_property_import', array($this, 'run_daily_import'));
        // Keep old hook for backward compatibility with manual cron calls
        add_action('pbe_daily_property_import', array($this, 'run_daily_import'));
    }

    /**
     * Executes the daily cron import completely
     * 
     * @return true|WP_Error Returns true on success, or a WP_Error on failure.
     */
    public function run_daily_import( $skip = 0 ) {
        $limit = 10; // Reduce batch size to 10 to completely eliminate PHP timeouts on WAMP
        
        $result = $this->run_batch_import( $limit, $skip );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        
        if ( $result['count'] == $limit ) {
            // There are likely more properties. Chain the next batch into the cron queue immediately!
            $next_skip = $skip + $limit;
            wp_schedule_single_event( time(), 'pbe_auto_property_import', array( $next_skip ) );
        } else {
            // Done! Save the last successful sync completion time for the active platform
            $platform_id = get_option('pbe_active_platform', 'guesty');
            update_option('pbe_last_property_sync_' . $platform_id, time());
        }
        
        return true;
    }

    /**
     * Executes a single batch import
     * 
     * @param int $limit
     * @param int $skip
     * @return array|WP_Error Returns array with count on success
     */
    public function run_batch_import( $limit = 10, $skip = 0 ) {
        $platform_id = get_option('pbe_active_platform', 'guesty');
        $adapter = PBE_Platform_Factory::get_adapter($platform_id);
        
        if ( is_wp_error($adapter) ) {
            return $adapter;
        }

        $auth = $adapter->authenticate();
        //echo '<pre>'; print_r($auth); echo '</pre><br>'; die;
        
        if ( is_wp_error($auth) ) {
            return $auth;
        }

        if ( $auth ) {
            $sync_source = get_option('pbe_sync_source', 'all');
            $raw_properties = array();

            $selected_ids = array();
            if ( $sync_source === 'selected_ids' ) {
                $ids_string = get_option('pbe_sync_property_ids', '');
                $selected_ids = array_filter( array_map( 'trim', explode( ',', $ids_string ) ) );
            }

            // Sync amenities globally on the first batch run
            if ( $skip == 0 && method_exists($adapter, 'sync_amenity_groups') ) {
                $adapter->sync_amenity_groups($selected_ids);
            }

            if ( $sync_source === 'selected_ids' ) {
                $ids = $selected_ids;
                
                if ( empty( $ids ) ) {
                    return new WP_Error('no_ids', 'Sync source is set to Selected IDs, but no IDs were provided in settings.');
                }
                
                // Get the slice for this specific batch
                $batch_ids = array_slice( $ids, $skip, $limit );
                
                foreach ( $batch_ids as $id ) {
                    // Note: fetch_single_property from adapter expects platform_id, returns mapped data or error
                    $prop_data = $adapter->fetch_single_property( $id );
                    
                    if ( ! is_wp_error( $prop_data ) && ! empty( $prop_data ) ) {
                        // The existing method expects mapped data, not raw, so let's trick it or handle it.
                        // Wait, map_property is called below. We need the RAW property from API to be mapped?
                        // Actually, fetch_single_property returns already MAPPED data.
                        // To keep the loop clean, we'll store mapped properties differently or just insert them.
                        $raw_properties[] = array( '_pre_mapped' => $prop_data ); 
                    }
                }
            } else {
                // Default to 'all' properties paginated fetch
                $raw_properties = $adapter->fetch_properties( $limit, $skip );
                
                if ( is_wp_error($raw_properties) ) {
                    return $raw_properties;
                }
            }
            
            $count = 0;
            $details = array();
            if ( is_array($raw_properties) ) {
                foreach ($raw_properties as $raw) {
                    if ( isset( $raw['_pre_mapped'] ) ) {
                        $standardized = $raw['_pre_mapped'];
                    } else {
                        $standardized = $adapter->map_property($raw);
                    }
                    
                    $status = $this->create_or_update_property($standardized, $adapter);
                    if ($status) {
                        $details[] = $status;
                    }
                    $count++;
                }
            }
            return array( 'count' => $count, 'total_processed' => $skip + $count, 'details' => $details );
        }
        
        return new WP_Error('auth_failed', 'Authentication failed for the selected platform.');
    }

    /**
     * @param array $data Standardized property array
     * @param PBE_Platform_Interface $adapter The platform adapter
     */
    private function create_or_update_property($data, $adapter) {
        // Quick validation
        if ( empty($data['platform_id']) || empty($data['name']) ) {
            return false;
        }

        $existing = $this->find_existing_property($data['platform_id'], $data['platform_source']);

        $post_content = is_array($data['description']) ? wp_json_encode($data['description']) : (string) $data['description'];

        $post_data = array(
            'post_title'   => sanitize_text_field($data['name']),
            'post_content' => wp_kses_post($post_content),
            'post_status'  => 'publish',
            'post_type'    => 'property',
        );

        $status_text = 'inserted';
        if ($existing) {
            $post_data['ID'] = $existing;
            $post_id = wp_update_post($post_data);
            $status_text = 'updated';
        } else {
            $post_id = wp_insert_post($post_data);
            
            // First-Time Check: Sync calendar immediately for brand new properties
            if ( ! is_wp_error($post_id) && class_exists('PBE_Availability_Sync') ) {
                PBE_Availability_Sync::sync_property_availability( $data['platform_id'], $adapter );
            }
        }

        if (!is_wp_error($post_id)) {
            // General Meta
            update_post_meta($post_id, 'platform_source', sanitize_text_field($data['platform_source']));
            update_post_meta($post_id, 'platform_property_id', sanitize_text_field($data['platform_id']));
            
            // Property Details
            update_post_meta($post_id, 'price_per_night', floatval($data['price']));
            update_post_meta($post_id, 'bedrooms', intval($data['bedrooms']));
            update_post_meta($post_id, 'bathrooms', floatval($data['bathrooms']));
            update_post_meta($post_id, 'max_guests', intval($data['max_guests']));
            update_post_meta($post_id, 'min_nights', isset($data['min_nights']) ? intval($data['min_nights']) : 1);
            update_post_meta($post_id, 'max_nights', isset($data['max_nights']) ? intval($data['max_nights']) : 0);
            update_post_meta($post_id, 'currency', isset($data['currency']) ? sanitize_text_field($data['currency']) : 'USD');
            
            if ( isset($data['property_type']) ) {
                update_post_meta($post_id, 'property_type', sanitize_text_field($data['property_type']));
            }
            
            // New Extra Meta Support
            if ( isset($data['active']) ) {
                update_post_meta($post_id, 'is_active', $data['active'] ? '1' : '0');
            }
            if ( isset($data['room_type']) ) {
                update_post_meta($post_id, 'room_type', sanitize_text_field($data['room_type']));
            }
            if ( isset($data['is_listed']) ) {
                update_post_meta($post_id, 'is_listed', $data['is_listed'] ? '1' : '0');
            }
            if ( isset($data['area_square_feet']) ) {
                update_post_meta($post_id, 'area_square_feet', sanitize_text_field($data['area_square_feet']));
            }
            if ( isset($data['area_size']) ) {
                update_post_meta($post_id, 'area_size', sanitize_text_field($data['area_size']));
            }
            if ( isset($data['area_size_unit']) ) {
                update_post_meta($post_id, 'area_size_unit', sanitize_text_field($data['area_size_unit']));
            }
            if ( isset($data['sleeps_min']) ) {
                update_post_meta($post_id, 'sleeps_min', intval($data['sleeps_min']));
            }
            if ( isset($data['sleeps_max']) ) {
                update_post_meta($post_id, 'sleeps_max', intval($data['sleeps_max']));
            }
            if ( isset($data['propertyId_key']) && !empty($data['propertyId_key']) ) {
                update_post_meta($post_id, 'propertyId_key', sanitize_text_field($data['propertyId_key']));
            }
            if ( isset($data['house_rules']) ) {
                $rules = is_array($data['house_rules']) ? wp_json_encode($data['house_rules']) : $data['house_rules'];
                update_post_meta($post_id, 'house_rules', wp_kses_post($rules));
            }

            // New Hostaway specific fields
            if ( isset($data['booking_engine_markup']) ) update_post_meta($post_id, 'booking_engine_markup', floatval($data['booking_engine_markup']));
            if ( isset($data['damage_deposit']) )        update_post_meta($post_id, 'damage_deposit', floatval($data['damage_deposit']));
            if ( isset($data['instant_bookable']) )      update_post_meta($post_id, 'instant_bookable', intval($data['instant_bookable']));
            if ( isset($data['cleaning_fee']) )          update_post_meta($post_id, 'cleaning_fee', floatval($data['cleaning_fee']));
            if ( isset($data['check_in_start']) )        update_post_meta($post_id, 'check_in_start', sanitize_text_field($data['check_in_start']));
            if ( isset($data['check_in_end']) )          update_post_meta($post_id, 'check_in_end', sanitize_text_field($data['check_in_end']));
            if ( isset($data['check_out']) )             update_post_meta($post_id, 'check_out', sanitize_text_field($data['check_out']));
            if ( isset($data['tax_settings']) )         update_post_meta($post_id, 'pbe_hostaway_tax_settings', $data['tax_settings']);

            // Location
            update_post_meta($post_id, 'latitude', sanitize_text_field($data['lat']));
            update_post_meta($post_id, 'longitude', sanitize_text_field($data['lng']));
            if ( isset($data['full_address']) ) {
                update_post_meta($post_id, 'full_address', sanitize_text_field($data['full_address']));
            }
            if ( isset($data['public_address']) ) {
                update_post_meta($post_id, 'public_address', sanitize_text_field($data['public_address']));
            }
            if ( isset($data['street']) ) update_post_meta($post_id, 'street', sanitize_text_field($data['street']));
            if ( isset($data['address2']) ) update_post_meta($post_id, 'address2', sanitize_text_field($data['address2']));
            if ( isset($data['city']) ) update_post_meta($post_id, 'city', sanitize_text_field($data['city']));
            if ( isset($data['state']) ) update_post_meta($post_id, 'state', sanitize_text_field($data['state']));
            if ( isset($data['country']) ) update_post_meta($post_id, 'country', sanitize_text_field($data['country']));
            if ( isset($data['country_code']) ) update_post_meta($post_id, 'country_code', sanitize_text_field($data['country_code']));
            if ( isset($data['zip']) ) update_post_meta($post_id, 'zip', sanitize_text_field($data['zip']));
            
            // Amenities & Gallery
            if ( isset($data['amenities']) && is_array($data['amenities']) ) {
                $this->save_property_amenities($post_id, $data['amenities'], $data['platform_source']);
            }
            if ( isset($data['tags']) && is_array($data['tags']) ) {
                $tag_names = array_map('sanitize_text_field', $data['tags']);
                wp_set_object_terms($post_id, $tag_names, 'property_tag', false);
                
                // Tag each term with the platform source for filtering
                foreach ($tag_names as $tag_name) {
                    $tag_term = term_exists($tag_name, 'property_tag');
                    if ($tag_term) {
                        $tag_id = is_array($tag_term) ? $tag_term['term_id'] : $tag_term;
                        $this->tag_term_with_platform($tag_id, $data['platform_source']);
                    }
                }
            }
            
            if (isset($data['gallery_urls']) && is_array($data['gallery_urls']) && !empty($data['gallery_urls'])) {
                update_post_meta($post_id, 'property_gallery_urls', wp_json_encode($data['gallery_urls']));
                
                // Set featured image to first gallery URL if there is no thumbnail attached yet.
                update_post_meta($post_id, 'featured_image_url', esc_url_raw($data['gallery_urls'][0]));
            }

            // Sync Reviews
            $this->sync_property_reviews($post_id, $data['platform_id'], $adapter);
            
            // Per-Property sync timestamp
            update_post_meta($post_id, '_pbe_last_sync_time', time());
            
            return array( 'title' => $data['name'], 'status' => $status_text );
        }
        
        return false;
    }

    /**
     * Public wrapper for syncing reviews (used by manual AJAX sync)
     */
    public function sync_property_reviews_public($post_id, $platform_id, $adapter) {
        return $this->sync_property_reviews($post_id, $platform_id, $adapter);
    }

    /**
     * Saves amenities hierarchically and tags them with platform source
     */
    private function save_property_amenities($post_id, $amenities, $platform_source) {
        $term_ids = array();
        
        foreach ($amenities as $amenity) {
            $name = '';
            $group = '';
            
            if (is_array($amenity)) {
                $name  = isset($amenity['name']) ? sanitize_text_field($amenity['name']) : '';
                $group = !empty($amenity['group']) ? sanitize_text_field($amenity['group']) : 'Other';
            } else {
                $name = sanitize_text_field($amenity);
                $group = 'Other';
            }
            
            if (empty($name)) continue;
            
            $parent_id = 0;
            if (!empty($group)) {
                $group_term = term_exists($group, 'amenity');
                if (!$group_term) {
                    $group_term = wp_insert_term($group, 'amenity');
                }
                
                if (!is_wp_error($group_term)) {
                    $parent_id = is_array($group_term) ? $group_term['term_id'] : $group_term;
                    // Tag group with platform
                    $this->tag_term_with_platform($parent_id, $platform_source);
                }
            }
            
            // Find or create child term
            // First check if it exists ANYWHERE to avoid duplicate name errors
            $term = term_exists($name, 'amenity');
            
            if ($term) {
                $term_id = is_array($term) ? $term['term_id'] : $term;
                
                // If it exists, determine if we should update its parent
                $term_obj = get_term($term_id, 'amenity');
                if ($term_obj) {
                    $is_other_fallback = ($group === 'Other');
                    $has_no_parent = ($term_obj->parent == 0);
                    
                    // Rule: Only move the amenity if we have a REAL group name, 
                    // OR if it currently has no parent at all.
                    if (!$is_other_fallback || $has_no_parent) {
                        if ($term_obj->parent != $parent_id) {
                            wp_update_term($term_id, 'amenity', array('parent' => $parent_id));
                        }
                    }
                }
            } else {
                // It really doesn't exist, so create it
                $term = wp_insert_term($name, 'amenity', array('parent' => $parent_id));
                $term_id = !is_wp_error($term) ? $term['term_id'] : 0;
            }

            if ($term_id) {
                $term_ids[] = (int) $term_id;
                
                // Tag amenity with platform
                $this->tag_term_with_platform($term_id, $platform_source);
            }
        }
        
        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, 'amenity', false);
        }
    }

    /**
     * Tags a term with a platform source metadata
     */
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

    /**
     * Syncs external reviews for a specific property
     */
    private function sync_property_reviews($post_id, $platform_id, $adapter) {
        if ( ! method_exists($adapter, 'fetch_reviews') ) {
            return;
        }

        $reviews = $adapter->fetch_reviews($platform_id);
        if ( is_wp_error($reviews) || empty($reviews) ) {
            return;
        }

        foreach ($reviews as $rev) {
            $ext_id = isset($rev['external_id']) ? $rev['external_id'] : '';
            if ( empty($ext_id) ) continue;

            // Check if already exists
            global $wpdb;
            $existing_review = $wpdb->get_var($wpdb->prepare("
                SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = 'pbe_review_id' AND meta_value = %s
            ", $ext_id));

            $rev_data = array(
                'post_title'   => sanitize_text_field($rev['author']),
                'post_content' => wp_kses_post($rev['text']),
                'post_status'  => 'publish',
                'post_type'    => 'pbe_review',
                'post_parent'  => $post_id,
                'post_date'    => date('Y-m-d H:i:s', strtotime($rev['date']))
            );

            if ($existing_review) {
                $rev_data['ID'] = $existing_review;
                wp_update_post($rev_data);
                $review_post_id = $existing_review;
            } else {
                $review_post_id = wp_insert_post($rev_data);
            }

            if ($review_post_id && !is_wp_error($review_post_id)) {
                update_post_meta($review_post_id, 'pbe_review_id', sanitize_text_field($ext_id));
                update_post_meta($review_post_id, 'pbe_rating', sanitize_text_field($rev['rating']));
                update_post_meta($review_post_id, 'pbe_source', sanitize_text_field($rev['source']));
                
                if ( ! empty($rev['title']) ) {
                    update_post_meta($review_post_id, 'pbe_review_title', sanitize_text_field($rev['title']));
                }
                
                if ( ! empty($rev['reservation_id']) ) {
                    update_post_meta($review_post_id, 'pbe_platform_res_id', sanitize_text_field($rev['reservation_id']));
                }

                if ( ! empty($rev['listing_site']) ) {
                    update_post_meta($review_post_id, 'pbe_listing_site', sanitize_text_field($rev['listing_site']));
                }
                
                // Tag review with the same platform source as the parent property
                $platform_source = get_post_meta($post_id, 'platform_source', true);
                update_post_meta($review_post_id, 'pbe_platform_source', $platform_source);
            }
        }
    }

    /**
     * Look up existing property by platform ID
     */
    private function find_existing_property($platform_id, $platform_source) {
        global $wpdb;

        $query = $wpdb->prepare("
            SELECT post_id FROM $wpdb->postmeta 
            WHERE meta_key = 'platform_property_id' AND meta_value = %s
        ", $platform_id);

        $results = $wpdb->get_col($query);

        if (!empty($results)) {
            // Verify source matches as well
            foreach ($results as $id) {
                $source = get_post_meta($id, 'platform_source', true);
                if ($source === $platform_source) {
                    return $id;
                }
            }
        }
        return false;
    }
}

new PBE_Importer();
