<?php
/**
 * Admin Bar Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Admin_Bar {

    public function __construct() {
        add_action('admin_bar_menu', array($this, 'add_toolbar_sync_button'), 999);
        add_action('admin_init', array($this, 'listen_for_debug_sync'));
    }

    public function add_toolbar_sync_button($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $args = array(
            'id'    => 'pbe_admin_bar_sync',
            'title' => 'Sync Properties',
            'href'  => admin_url('admin.php?page=pbe-platform-settings&pbe_debug_sync=1'),
            'meta'  => array(
                'title'  => 'Trigger sync to import properties in batches',
                'target' => '_blank'
            ),
        );
        $wp_admin_bar->add_node($args);
        
        $wp_admin_bar->add_node(array(
            'id'     => 'pbe_admin_bar_sync_reset',
            'parent' => 'pbe_admin_bar_sync',
            'title'  => 'Reset Debug Sync',
            'href'   => admin_url('admin.php?page=pbe-platform-settings&pbe_debug_sync=1&pbe_reset=1'),
            'meta'   => array('target' => '_blank'),
        ));
    }
    
    public function listen_for_debug_sync() {
        if ( isset($_GET['pbe_debug_sync']) && $_GET['pbe_debug_sync'] == '1' && current_user_can('manage_options') ) {
            
            if ( isset($_GET['pbe_reset']) && $_GET['pbe_reset'] == '1' ) {
                delete_option('pbe_debug_sync_offset');
                echo '<div style="margin:20px;padding:15px;background:#e7f6ed;color:#207b4d;border:1px solid #c3e5d0;font-family:sans-serif;">Debug sync progress has been reset. <a href="'.admin_url('admin.php?page=pbe-platform-settings&pbe_debug_sync=1').'" style="font-weight:bold;color:#207b4d;">Start new sync &raquo;</a></div>';
                die();
            }

            if ( isset($_GET['offset']) ) {
                $offset = intval($_GET['offset']);
            } else {
                $offset = (int) get_option('pbe_debug_sync_offset', 0);
            }
            $limit  = 5;

            $platform_id = get_option('pbe_active_platform', 'guesty');
            
            if ( ! class_exists('PBE_Platform_Factory') || ! class_exists('PBE_Importer') ) {
                echo "<h1>Error</h1><p>PBE_Platform_Factory or PBE_Importer classes missing.</p>";
                die();
            }
            
            $adapter = PBE_Platform_Factory::get_adapter($platform_id);
            if ( is_wp_error($adapter) ) {
                echo "<h1>FACTORY ERROR</h1><pre>";
                print_r($adapter);
                die();
            }
            
            $auth = $adapter->authenticate();
            if ( is_wp_error($auth) ) {
                echo "<h1>AUTH ERROR</h1><pre>";
                print_r($auth);
                die();
            }
            
            $importer = new PBE_Importer();
            $result = $importer->run_batch_import($limit, $offset);
            
            if ( is_wp_error($result) ) {
                echo "<h1>IMPORT ERROR</h1><pre>";
                print_r($result);
                die();
            }
            
            // 2. Output the Header & Button
            echo '<h1>PBE Sync Debug Output</h1>';
            
            if ( $result['count'] == $limit ) {
                $next_offset = $offset + $limit;
                update_option('pbe_debug_sync_offset', $next_offset);
                
                $next_url = admin_url('admin.php?page=pbe-platform-settings&pbe_debug_sync=1&offset=' . $next_offset);
                echo '<div style="margin:20px 0;"><a href="' . esc_url($next_url) . '" style="background:#007cba;color:#fff;padding:10px 15px;text-decoration:none;border-radius:3px;">Sync Next 5 Properties (resume from '.$next_offset.') &raquo;</a></div>';
            } else {
                delete_option('pbe_debug_sync_offset');
                echo '<div style="margin:20px 0;padding:10px 15px;background:#e7f6ed;color:#207b4d;display:inline-block;border-radius:3px;border:1px solid #c3e5d0;">All properties synced! Progress has been reset.</div>';
            }

            // 3. Output the Debug Log
            echo '<pre style="background:#111; color:#0f0; padding:20px; white-space:pre-wrap;">';
            echo "--- Selected Platform: " . esc_html($platform_id) . " ---\n\n";
            echo "--- Testing Authentication ---\n";
            echo "Auth Success! Token Generated or using Transient.\n\n";
            echo "--- Importing Properties (Batch of 5) ---\n";
            echo "Total Properties Processed in this batch: " . $result['count'] . "\n\n";
            
            if ( !empty($result['details']) ) {
                foreach ( $result['details'] as $detail ) {
                    $status_color = $detail['status'] === 'inserted' ? '#0f0' : '#ff0';
                    echo "<span style=\"color:$status_color\">[" . strtoupper($detail['status']) . "]</span> " . esc_html($detail['title']) . "\n";
                }
            } else {
                echo "No properties returned or found in this batch.\n";
            }
            
            echo "\n\nSync Output Complete.\n";
            echo '</pre>';
            
            die();
        }
    }
}
