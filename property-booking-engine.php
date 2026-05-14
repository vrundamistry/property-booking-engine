<?php
/**
 * Plugin Name: Property Booking Engine
 * Plugin URI: http://example.com/property-booking-engine
 * Description: Platform-agnostic property importer and booking engine. No ACF or Elementor required.
 * Version: 2.0.0
 * Author: Developer Team
 * Text Domain: property-booking-engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PBE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PBE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Core Includes
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-cpt.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-platform-interface.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-platform-factory.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-settings.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-importer.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-sync-handler.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-admin-bar.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-shortcodes.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-auto-setup.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-appearance.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-template-loader.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-page-meta.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-review-handler.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-booking-handler.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-availability-sync.php';
require_once PBE_PLUGIN_DIR . 'includes/class-pbe-filter-helper.php';

// Load Platform Adapters
require_once PBE_PLUGIN_DIR . 'platforms/guesty/class-pbe-guesty-api.php';
require_once PBE_PLUGIN_DIR . 'platforms/guesty/class-pbe-guesty-adapter.php';
require_once PBE_PLUGIN_DIR . 'platforms/hostaway/class-pbe-hostaway-api.php';
require_once PBE_PLUGIN_DIR . 'platforms/hostaway/class-pbe-hostaway-adapter.php';
require_once PBE_PLUGIN_DIR . 'platforms/ownerrez/class-pbe-ownerrez-api.php';
require_once PBE_PLUGIN_DIR . 'platforms/ownerrez/class-pbe-ownerrez-adapter.php';
require_once PBE_PLUGIN_DIR . 'platforms/hostfully/class-pbe-hostfully-api.php';
require_once PBE_PLUGIN_DIR . 'platforms/hostfully/class-pbe-hostfully-adapter.php';

/**
 * Initialize Plugin
 */
function pbe_init() {
    $cpt = new PBE_CPT();
    $cpt->init();

    new PBE_Settings();
    new PBE_Admin_Bar();
    new PBE_Sync_Handler();
    new PBE_Auto_Setup();
    new PBE_Appearance();
    new PBE_Template_Loader();
    new PBE_Page_Meta();

    $shortcodes = new PBE_Shortcodes();
    $shortcodes->init();

    new PBE_Review_Handler();

    $booking = new PBE_Booking_Handler();
    $booking->init();

    $avail_sync = new PBE_Availability_Sync();
    $avail_sync->init();
}
add_action( 'plugins_loaded', 'pbe_init' );

/**
 * Register Activation hook
 */
function pbe_activate() {
    require_once PBE_PLUGIN_DIR . 'includes/class-pbe-cpt.php';
    $cpt = new PBE_CPT();
    $cpt->register_post_type();
    flush_rewrite_rules();

    require_once PBE_PLUGIN_DIR . 'includes/class-pbe-auto-setup.php';
    PBE_Auto_Setup::run_activation_setup();

    wp_clear_scheduled_hook( 'pbe_daily_property_import' );
    
    $schedule = get_option( 'pbe_auto_sync_schedule', 'manual' );
    if ( $schedule !== 'manual' && $schedule !== '' ) {
        if ( ! wp_next_scheduled( 'pbe_auto_property_import' ) ) {
            wp_schedule_event( time(), $schedule, 'pbe_auto_property_import' );
        }
    }

    if ( ! wp_next_scheduled( 'pbe_auto_availability_sync' ) ) {
        wp_schedule_event( time(), 'half_hourly', 'pbe_auto_availability_sync' );
    }

    pbe_create_db_tables();
}
register_activation_hook( __FILE__, 'pbe_activate' );

function pbe_create_db_tables() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pbe_calendar_dates';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        platform_property_id varchar(100) NOT NULL,
        calendar_date date NOT NULL,
        status varchar(50) NOT NULL,
        min_nights int(11) DEFAULT 1,
        guests int(11) DEFAULT 1,
        cta tinyint(1) DEFAULT 0,
        ctd tinyint(1) DEFAULT 0,
        PRIMARY KEY  (id),
        KEY platform_property_id (platform_property_id),
        KEY calendar_date (calendar_date)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    // Ensure columns exist even if dbDelta skips them (sometimes happens on certain MySQL configs)
    $wpdb->query("ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS cta tinyint(1) DEFAULT 0 AFTER guests");
    $wpdb->query("ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS ctd tinyint(1) DEFAULT 0 AFTER cta");
}

add_filter( 'cron_schedules', 'pbe_cron_schedules' );
function pbe_cron_schedules( $schedules ) {
    if ( ! isset( $schedules['half_hourly'] ) ) {
        $schedules['half_hourly'] = array(
            'interval' => 1800,
            'display'  => __( 'Once Half Hourly', 'property-booking-engine' )
        );
    }
    return $schedules;
}

/**
 * Register Deactivation hook
 */
function pbe_deactivate() {
    wp_clear_scheduled_hook( 'pbe_daily_property_import' );
    wp_clear_scheduled_hook( 'pbe_auto_property_import' );
    wp_clear_scheduled_hook( 'pbe_auto_availability_sync' );
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pbe_deactivate' );
