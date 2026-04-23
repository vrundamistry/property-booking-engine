<?php
/**
 * Availability Sync Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Availability_Sync {

    public function init() {
        add_action('pbe_auto_availability_sync', array($this, 'run_availability_sync'));
        add_action('pbe_auto_availability_sync_batch', array($this, 'run_availability_sync'));
        add_action('wp_ajax_pbe_manual_availability_sync', array($this, 'ajax_manual_sync'));
    }

    /**
     * Executes the half_hourly cron sync for availability
     */
    public function run_availability_sync() {
        if ( function_exists('set_time_limit') ) {
            set_time_limit(0);
        }

        $platform_id = get_option('pbe_active_platform', 'guesty');
        $adapter = PBE_Platform_Factory::get_adapter($platform_id);
        
        if ( is_wp_error($adapter) ) {
            return $adapter;
        }

        $auth = $adapter->authenticate();
        if ( is_wp_error($auth) || !$auth ) {
            return new WP_Error('auth_failed', 'Authentication failed for the selected platform.');
        }

        global $wpdb;
        $active_properties = $wpdb->get_col("
            SELECT pm.meta_value FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'platform_property_id' 
            AND p.post_type = 'property' AND p.post_status = 'publish'
        ");

        if ( empty($active_properties) ) {
            update_option('pbe_avail_cron_offset', 0);
            return true;
        }

        $offset = (int) get_option('pbe_avail_cron_offset', 0);
        $batch_size = 15; // 15 per cron cycle
        
        $batch = array_slice($active_properties, $offset, $batch_size);

        if (empty($batch)) {
            // We finished all properties! Reset for the next 30-min mark cycle.
            update_option('pbe_avail_cron_offset', 0);
            
            $platform_id = get_option('pbe_active_platform', 'guesty');
            update_option('pbe_last_calendar_sync_' . $platform_id, time());

            return true;
        }

        foreach ( $batch as $pid ) {
            $this->sync_property_availability($pid, $adapter);
        }

        // We are not at the end. Schedule a one-off mini cron to process the next batch in 20 seconds.
        $new_offset = $offset + count($batch);
        update_option('pbe_avail_cron_offset', $new_offset);
        
        if ( ! wp_next_scheduled( 'pbe_auto_availability_sync_batch' ) ) {
            wp_schedule_single_event( time() + 20, 'pbe_auto_availability_sync_batch' );
        }

        return true;
    }

    /**
     * Syncs a single property's availability for the next 365 days
     */
    public static function sync_property_availability($platform_property_id, $adapter) {
        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime('+365 days'));

        $calendar = $adapter->fetch_availability($platform_property_id, $from, $to, true); // force_refresh = true to bypass transient cache

        if ( is_wp_error($calendar) ) {
            return $calendar;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'pbe_calendar_dates';

        // Clear existing future dates for this property to avoid duplicates on sparse storage
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table_name WHERE platform_property_id = %s AND calendar_date >= %s",
            $platform_property_id,
            $from
        ) );

        $days = array();
        if ( isset($calendar['data']['days']) && is_array($calendar['data']['days']) ) {
            $days = $calendar['data']['days'];
        } elseif ( isset($calendar['days']) && is_array($calendar['days']) ) {
            $days = $calendar['days'];
        }

        if ( ! empty($days) ) {
            foreach ( $days as $day ) {
                $status = isset($day['status']) ? strtolower($day['status']) : 'available';
                $guests = 1; // Default fallback

                // Check if there is an attached reservation and pull its guestsCount
                if ( isset( $day['reservation']['guestsCount'] ) ) {
                    $guests = intval( $day['reservation']['guestsCount'] );
                } elseif ( isset( $day['blockRefs'][0]['reservation']['guestsCount'] ) ) {
                    $guests = intval( $day['blockRefs'][0]['reservation']['guestsCount'] );
                }

                // Dense Storage: Complete 365-day tracking for precision minNights filtering
                $wpdb->insert(
                    $table_name,
                    array(
                        'platform_property_id' => $platform_property_id,
                        'calendar_date'        => isset($day['date']) ? $day['date'] : '',
                        'status'               => $status,
                        'min_nights'           => isset($day['minNights']) ? intval($day['minNights']) : 1,
                        'guests'               => $guests,
                        'cta'                  => !empty($day['cta']) ? 1 : 0,
                        'ctd'                  => !empty($day['ctd']) ? 1 : 0,
                    ),
                    array( '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
                );
            }
        }

        // Per-Property sync timestamp
        $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'platform_property_id' AND meta_value = %s", $platform_property_id));
        if ($post_id) {
            update_post_meta($post_id, '_pbe_last_sync_time', time());
        }

        return true;
    }

    /**
     * AJAX handler for Manual Sync
     */
    public function ajax_manual_sync() {
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $source = isset($_POST['sync_source']) ? sanitize_text_field($_POST['sync_source']) : 'all';
        $ids_string = isset($_POST['specific_ids']) ? sanitize_text_field($_POST['specific_ids']) : '';

        $platform_id = get_option('pbe_active_platform', 'guesty');
        $adapter = PBE_Platform_Factory::get_adapter($platform_id);
        
        if ( is_wp_error($adapter) ) {
            wp_send_json_error( $adapter->get_error_message() );
        }

        $auth = $adapter->authenticate();
        if ( is_wp_error($auth) || !$auth ) {
            wp_send_json_error('Authentication failed.');
        }

        global $wpdb;
        $target_ids = array();

        if ( $source === 'selected_ids' && !empty($ids_string) ) {
            $target_ids = array_filter( array_map( 'trim', explode( ',', $ids_string ) ) );
        } else {
            $target_ids = $wpdb->get_col("
                SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = 'platform_property_id' 
                AND p.post_type = 'property' AND p.post_status = 'publish'
            ");
        }

        if ( empty($target_ids) ) {
            wp_send_json_error( 'No properties found to sync.' );
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 5; // process 5 calendars per ajax request

        $total_properties = count($target_ids);
        $batch_ids = array_slice($target_ids, $offset, $batch_size);

        if ( empty($batch_ids) ) {
            $platform_id = get_option('pbe_active_platform', 'guesty');
            update_option('pbe_last_calendar_sync_' . $platform_id, time());

            wp_send_json_success( array( 'done' => true, 'message' => "All $total_properties calendars successfully synced." ) );
        }

        $count = 0;
        foreach ( $batch_ids as $pid ) {
            $res = self::sync_property_availability($pid, $adapter);
            if ( ! is_wp_error($res) ) {
                $count++;
            }
        }

        $new_offset = $offset + count($batch_ids);
        
        wp_send_json_success( array( 
            'done' => false,
            'new_offset' => $new_offset,
            'message' => "Synced $new_offset of $total_properties calendars..." 
        ) );
    }
}
