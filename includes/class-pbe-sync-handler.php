<?php
/**
 * Property Sync Handler (AJAX)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Sync_Handler {

    public function __construct() {
        add_action('wp_ajax_pbe_manual_sync_properties', array($this, 'manual_sync_ajax'));
        add_action('wp_ajax_pbe_manual_sync_reviews',    array($this, 'manual_sync_reviews_ajax'));
        add_action('wp_ajax_pbe_sync_single_property_reviews', array($this, 'sync_single_reviews_ajax'));
    }

    /**
     * Sync ALL Reviews (Batch)
     */
    public function manual_sync_reviews_ajax() {
        check_ajax_referer('pbe_sync_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        // Get offset from POST, but if it's 0, check if we have a saved "resume" offset
        $platform_id = get_option('pbe_active_platform', 'guesty');
        $offset_key  = 'pbe_review_sync_last_offset_' . $platform_id;
        
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        if ( $offset === 0 ) {
            $offset = intval( get_option($offset_key, 0) );
        }

        $limit  = 5; // 5 properties per batch for reviews to avoid timeout
        
        $args = array(
            'post_type'      => 'property',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => 'platform_source',
                    'value' => $platform_id
                )
            )
        );

        $query = new WP_Query($args);
        $property_ids = $query->posts;
        
        if ( empty($property_ids) ) {
            update_option('pbe_last_review_sync_' . $platform_id, time());
            update_option($offset_key, 0); // Clear on completion
            wp_send_json_success(array('done' => true, 'count' => 0));
        }

        if (class_exists('PBE_Importer')) {
            $importer = new PBE_Importer();
            $adapter  = PBE_Platform_Factory::get_adapter($platform_id);
            
            if (is_wp_error($adapter)) {
                wp_send_json_error($adapter->get_error_message());
            }

            $adapter->authenticate();

            foreach ($property_ids as $post_id) {
                $platform_property_id = get_post_meta($post_id, 'platform_property_id', true);
                if ($platform_property_id) {
                    $importer->sync_property_reviews_public($post_id, $platform_property_id, $adapter);
                }
            }

            $count = count($property_ids);
            $new_offset = $offset + $count;
            $done = $query->max_num_pages <= 1 || $count < $limit;

            if ($done) {
                update_option('pbe_last_review_sync_' . $platform_id, time());
                update_option($offset_key, 0); // Reset on completion
            } else {
                update_option($offset_key, $new_offset); // Save progress
            }

            wp_send_json_success(array(
                'count'      => $count,
                'new_offset' => $new_offset,
                'done'       => $done,
                'message'    => "Processed $count properties."
            ));
        }

        wp_send_json_error('Importer error.');
    }

    /**
     * Sync Specific Property Reviews
     */
    public function sync_single_reviews_ajax() {
        check_ajax_referer('pbe_sync_nonce', 'security');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Invalid property.');
        }

        $platform_property_id = get_post_meta($post_id, 'platform_property_id', true);
        if (!$platform_property_id) {
            wp_send_json_error('No platform ID found for this property.');
        }

        if (class_exists('PBE_Importer')) {
            $importer = new PBE_Importer();
            $platform_id = get_post_meta($post_id, 'platform_source', true);
            
            if (!$platform_id) {
                wp_send_json_error('No platform source found for this property.');
            }
            
            $adapter = PBE_Platform_Factory::get_adapter($platform_id);
            
            if (is_wp_error($adapter)) wp_send_json_error($adapter->get_error_message());
            $adapter->authenticate();

            $importer->sync_property_reviews_public($post_id, $platform_property_id, $adapter);
            wp_send_json_success('Reviews synced successfully.');
        }

        wp_send_json_error('Sync failed.');
    }

    public function manual_sync_ajax() {
        check_ajax_referer('pbe_sync_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        // Trigger the Importer logic via batches
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit  = 10; // 10 properties per batch
        
        if (class_exists('PBE_Importer')) {
            $importer = new PBE_Importer();
            $result = $importer->run_batch_import($limit, $offset); 
            
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            } else {
                $count = $result['count'];
                $new_offset = $offset + $count;
                
                $done = $count < $limit;
                
                if ( $done ) {
                    $platform_id = get_option('pbe_active_platform', 'guesty');
                    update_option('pbe_last_property_sync_' . $platform_id, time());
                }

                $response = array(
                    'count'      => $count,
                    'new_offset' => $new_offset,
                    'done'       => $done,
                    'message'    => 'Imported ' . $count . ' properties. Total processed: ' . $new_offset . '.'
                );
                wp_send_json_success( $response );
            }
        } else {
            wp_send_json_error('Importer class not found.');
        }
    }
}
