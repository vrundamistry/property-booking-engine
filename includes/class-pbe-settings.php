<?php
/**
 * Platform Settings Page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Register Settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Handle schedule change
        add_action('update_option_pbe_auto_sync_schedule', array($this, 'update_sync_schedule'), 10, 3);
    }

    public function update_sync_schedule($old_value, $value, $option) {
        // Clear any existing hook
        wp_clear_scheduled_hook('pbe_auto_property_import');
        // Clear old legacy hook just in case
        wp_clear_scheduled_hook('pbe_daily_property_import');
        
        if ($value !== 'manual' && $value !== '') {
            wp_schedule_event(time(), $value, 'pbe_auto_property_import');
        }
    }


    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_pbe-platform-settings') {
            return;
        }
        
        // Use filemtime for cache busting
        $js_path = PBE_PLUGIN_DIR . 'admin/js/settings.js';
        $js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : '1.1.0';
        
        wp_enqueue_script('pbe-admin-settings-js', PBE_PLUGIN_URL . 'admin/js/settings.js', array('jquery'), $js_ver, true);
        
        // Pass Ajax URL
        wp_localize_script('pbe-admin-settings-js', 'pbe_ajax_obj', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('pbe_sync_nonce')
        ));
    }

    public function add_settings_page() {
        add_menu_page(
            'Platform Settings',
            'Property Booking',
            'manage_options',
            'pbe-platform-settings',
            array($this, 'render_settings_page'),
            'dashicons-building',
            30
        );
    }

    public function register_settings() {
        // Platform Selection
        register_setting('pbe_settings_group', 'pbe_active_platform');
        
        // Guesty
        register_setting('pbe_settings_group', 'pbe_guesty_client_id');
        register_setting('pbe_settings_group', 'pbe_guesty_client_secret');
        register_setting('pbe_settings_group', 'pbe_guesty_account_id');
        register_setting('pbe_settings_group', 'pbe_guesty_api_endpoint');
        register_setting('pbe_settings_group', 'pbe_guesty_booking_domain');
        register_setting('pbe_settings_group', 'pbe_default_booking_widget');
        
        // Hostaway
        register_setting('pbe_settings_group', 'pbe_hostaway_api_key');
        register_setting('pbe_settings_group', 'pbe_hostaway_account_id');
        register_setting('pbe_settings_group', 'pbe_hostaway_manual_token');
        register_setting('pbe_settings_group', 'pbe_hostaway_booking_domain');
        
        // OwnerRez
        register_setting('pbe_settings_group', 'pbe_ownerrez_basic_auth');
        register_setting('pbe_settings_group', 'pbe_ownerrez_booking_widget_id');
        register_setting('pbe_settings_group', 'pbe_ownerrez_calendar_widget_id');
        
        // Hostfully
        register_setting('pbe_settings_group', 'pbe_hostfully_api_key');
        
        // Sync Settings
        register_setting('pbe_settings_group', 'pbe_sync_source');
        register_setting('pbe_settings_group', 'pbe_sync_property_ids');
        register_setting('pbe_settings_group', 'pbe_auto_sync_schedule');
        
        // Calendar Sync Settings
        register_setting('pbe_settings_group', 'pbe_avail_sync_source');
        register_setting('pbe_settings_group', 'pbe_avail_sync_property_ids');

        // Testing & Caching
        register_setting('pbe_settings_group', 'pbe_test_mode');
        register_setting('pbe_settings_group', 'pbe_test_listing_id');
        register_setting('pbe_settings_group', 'pbe_cache_duration');

        // Stripe Payments
        register_setting('pbe_settings_group', 'pbe_stripe_mode');
        register_setting('pbe_settings_group', 'pbe_stripe_test_pub');
        register_setting('pbe_settings_group', 'pbe_stripe_test_sec');
        register_setting('pbe_settings_group', 'pbe_stripe_live_pub');
        register_setting('pbe_settings_group', 'pbe_stripe_live_sec');
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Platform Settings</h1>
            
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="#" class="nav-tab nav-tab-active" data-tab="general">General</a>
                <a href="#" class="nav-tab" data-tab="sync">Sync</a>
                <a href="#" class="nav-tab" data-tab="payments">Payments</a>
                <a href="#" class="nav-tab" data-tab="advanced">Advanced</a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields('pbe_settings_group'); ?>
                <?php do_settings_sections('pbe_settings_group'); ?>
                
                <!-- TAB: General -->
                <div id="pbe-tab-general" class="pbe-tab-content">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Select Platform</th>
                            <td>
                                <select name="pbe_active_platform" id="pbe_active_platform">
                                    <option value="guesty" <?php selected(get_option('pbe_active_platform'), 'guesty'); ?>>Guesty</option>
                                    <option value="hostaway" <?php selected(get_option('pbe_active_platform'), 'hostaway'); ?>>Hostaway</option>
                                    <option value="ownerrez" <?php selected(get_option('pbe_active_platform'), 'ownerrez'); ?>>OwnerRez</option>
                                    <option value="hostfully" <?php selected(get_option('pbe_active_platform'), 'hostfully'); ?>>Hostfully</option>
                                    <option value="custom" <?php selected(get_option('pbe_active_platform'), 'custom'); ?>>Custom API</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <!-- Guesty Fields -->
                    <div id="pbe_fields_guesty" class="pbe-platform-fields" style="display:none;">
                        <h3>Guesty Settings</h3>
                        <table class="form-table">
                            <tr><th scope="row">Client ID</th><td><input type="text" name="pbe_guesty_client_id" value="<?php echo esc_attr(get_option('pbe_guesty_client_id')); ?>" class="regular-text"></td></tr>
                            <tr><th scope="row">Client Secret</th><td><input type="password" name="pbe_guesty_client_secret" value="<?php echo esc_attr(get_option('pbe_guesty_client_secret')); ?>" class="regular-text"></td></tr>
                            <tr><th scope="row">Account ID</th><td><input type="text" name="pbe_guesty_account_id" value="<?php echo esc_attr(get_option('pbe_guesty_account_id')); ?>" class="regular-text"></td></tr>
                            <tr><th scope="row">API Endpoint</th><td><input type="text" name="pbe_guesty_api_endpoint" value="<?php echo esc_attr(get_option('pbe_guesty_api_endpoint')); ?>" class="regular-text"></td></tr>
                            <tr>
                                <th scope="row">Booking Engine Domain</th>
                                <td>
                                    <input type="text" name="pbe_guesty_booking_domain" value="<?php echo esc_attr(get_option('pbe_guesty_booking_domain')); ?>" class="regular-text" placeholder="yourbrand.guestybookings.com">
                                    <p class="description">Your custom Guesty Direct Booking subdomain (e.g. <strong>brand.guestybookings.com</strong>).</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Hostaway Fields -->
                    <div id="pbe_fields_hostaway" class="pbe-platform-fields" style="display:none;">
                        <h3>Hostaway Settings</h3>
                        <table class="form-table">
                            <tr><th scope="row">API Key</th><td><input type="text" name="pbe_hostaway_api_key" value="<?php echo esc_attr(get_option('pbe_hostaway_api_key')); ?>" class="regular-text"></td></tr>
                            <tr><th scope="row">Account ID</th><td><input type="text" name="pbe_hostaway_account_id" value="<?php echo esc_attr(get_option('pbe_hostaway_account_id')); ?>" class="regular-text"></td></tr>
                            <tr>
                                <th scope="row">Booking Engine Domain</th>
                                <td>
                                    <input type="text" name="pbe_hostaway_booking_domain" value="<?php echo esc_attr(get_option('pbe_hostaway_booking_domain')); ?>" class="regular-text" placeholder="brand.holidayfuture.com">
                                    <p class="description">Your Hostaway / Holiday Future booking domain (e.g. <strong>jupitervacationrentals.holidayfuture.com</strong>).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Manual Access Token</th>
                                <td>
                                    <textarea name="pbe_hostaway_manual_token" class="large-text" rows="3"><?php echo esc_textarea(get_option('pbe_hostaway_manual_token')); ?></textarea>
                                    <p class="description"><strong>Note:</strong> If provided, this token will be used directly. Leave empty to use API Key/Account ID for auto-generation.</p>
                                    <div style="margin-top:10px; padding:10px; background:#fff8e1; border-left:4px solid #ffc107;">
                                        <h4 style="margin:0 0 5px 0;">⚠️ Token Expiration Warning</h4>
                                        <p style="margin:0; font-size:12px;">Manual tokens are temporary. If your sync stops working, your token has likely expired. You must replace it with a fresh one or provide an API Key for automatic renewal.</p>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- OwnerRez Fields -->
                    <div id="pbe_fields_ownerrez" class="pbe-platform-fields" style="display:none;">
                        <h3>OwnerRez Settings</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Basic Auth Token</th>
                                <td>
                                    <input type="text" name="pbe_ownerrez_basic_auth" value="<?php echo esc_attr(get_option('pbe_ownerrez_basic_auth')); ?>" class="regular-text">
                                    <p class="description">Paste your Basic Auth token here (the exact encoded string or token).</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Hostfully Fields -->
                    <div id="pbe_fields_hostfully" class="pbe-platform-fields" style="display:none;">
                        <h3>Hostfully Settings</h3>
                        <table class="form-table">
                            <tr><th scope="row">API Key</th><td><input type="text" name="pbe_hostfully_api_key" value="<?php echo esc_attr(get_option('pbe_hostfully_api_key')); ?>" class="regular-text"></td></tr>
                        </table>
                    </div>

                    <hr>

                    <h3>Global Display Settings</h3>
                    <table class="form-table">
                        <tr class="pbe-default-widget-setting">
                            <th scope="row">Default Booking Widget</th>
                            <td>
                                <select name="pbe_default_booking_widget">
                                    <option value="standard" <?php selected(get_option('pbe_default_booking_widget', 'standard'), 'standard'); ?>>Standard (Platform Redirect)</option>
                                    <option value="native" <?php selected(get_option('pbe_default_booking_widget'), 'native'); ?>>Native (On-Site Booking)</option>
                                </select>
                                <p class="description">Choose the default booking widget for all property pages.</p>
                            </td>
                        </tr>
                        <tr class="pbe-ownerrez-global-setting" style="display:none;">
                            <th scope="row">OwnerRez Booking Widget ID</th>
                            <td>
                                <input type="text" name="pbe_ownerrez_booking_widget_id" value="<?php echo esc_attr(get_option('pbe_ownerrez_booking_widget_id')); ?>" class="regular-text">
                                <p class="description">Your global OwnerRez Booking Widget ID (e.g. 1a2b3c4d...)</p>
                            </td>
                        </tr>
                        <tr class="pbe-ownerrez-global-setting" style="display:none;">
                            <th scope="row">OwnerRez Calendar Widget ID</th>
                            <td>
                                <input type="text" name="pbe_ownerrez_calendar_widget_id" value="<?php echo esc_attr(get_option('pbe_ownerrez_calendar_widget_id')); ?>" class="regular-text">
                                <p class="description">Your global OwnerRez Availability Calendar Widget ID.</p>
                            </td>
                        </tr>
                    </table>

                    <hr>

                    <h3>System Tools</h3>
                    <p>Use the button below to regenerate the Front Page and re-initialize shortcodes.</p>
                    <button class="button" id="pbe_run_setup_btn">Run Auto-Setup Now</button>
                    <span id="pbe_setup_status" style="margin-left:10px; font-weight:bold;"></span>
                </div>

                <!-- TAB: Sync -->
                <div id="pbe-tab-sync" class="pbe-tab-content" style="display:none;">
                    <h3>Property Sync Settings</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Sync Properties From</th>
                            <td>
                                <select name="pbe_sync_source">
                                    <option value="all" <?php selected(get_option('pbe_sync_source'), 'all'); ?>>All Properties</option>
                                    <option value="selected_ids" <?php selected(get_option('pbe_sync_source'), 'selected_ids'); ?>>Selected Property IDs</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="pbe_sync_property_ids_wrapper" style="display:none;">
                            <th scope="row">Specific Property IDs</th>
                            <td>
                                <input type="text" name="pbe_sync_property_ids" value="<?php echo esc_attr(get_option('pbe_sync_property_ids')); ?>" class="regular-text">
                                <p class="description">Enter comma-separated platform IDs (e.g. 5d8e9f2a, 6a1b2c3d)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Auto Sync Schedule</th>
                            <td>
                                <select name="pbe_auto_sync_schedule">
                                    <option value="manual" <?php selected(get_option('pbe_auto_sync_schedule', 'manual'), 'manual'); ?>>Manual</option>
                                    <option value="hourly" <?php selected(get_option('pbe_auto_sync_schedule'), 'hourly'); ?>>Every Hour</option>
                                    <option value="twicedaily" <?php selected(get_option('pbe_auto_sync_schedule'), 'twicedaily'); ?>>Twice Daily</option>
                                    <option value="daily" <?php selected(get_option('pbe_auto_sync_schedule'), 'daily'); ?>>Daily</option>
                                </select>
                                <p class="description">Uses WP Cron to schedule imports.</p>
                            </td>
                        </tr>
                    </table>

                    <hr>
                    
                    <h3>Manual Property Sync</h3>
                    <p>Trigger an immediate property import sync based on the settings above.</p>
                    <button class="button button-primary" id="pbe_sync_now_btn">Sync Properties Now</button>
                    <span id="pbe_sync_status" style="margin-left:10px; font-weight:bold;"></span>
                    <div style="margin-top:10px;">
                        <?php echo $this->get_last_sync_html('property'); ?>
                    </div>

                    <hr>

                    <h3>Manual Calendar Sync</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Sync Calendars From</th>
                            <td>
                                <select name="pbe_avail_sync_source" id="pbe_avail_sync_source">
                                    <option value="all" <?php selected(get_option('pbe_avail_sync_source', 'all'), 'all'); ?>>All Properties</option>
                                    <option value="selected_ids" <?php selected(get_option('pbe_avail_sync_source'), 'selected_ids'); ?>>Selected Property IDs</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="pbe_avail_sync_property_ids_wrapper" style="display:none;">
                            <th scope="row">Specific Property IDs</th>
                            <td>
                                <input type="text" name="pbe_avail_sync_property_ids" id="pbe_avail_sync_property_ids" value="<?php echo esc_attr(get_option('pbe_avail_sync_property_ids')); ?>" class="regular-text">
                                <p class="description">Enter comma-separated platform IDs (e.g. 5d8e9f2a, 6a1b2c3d)</p>
                            </td>
                        </tr>
                    </table>
                    <p>Trigger an immediate calendar availability sync based on the settings above.</p>
                    <button class="button button-primary" id="pbe_avail_sync_now_btn">Sync Calendars Now</button>
                    <span id="pbe_avail_sync_status" style="margin-left:10px; font-weight:bold;"></span>
                    <div style="margin-top:10px;">
                        <?php echo $this->get_last_sync_html('calendar'); ?>
                    </div>

                    <hr>
                    
                    <h3>Manual Review Sync</h3>
                    <p>Trigger an immediate sync for all property reviews from the active platform.</p>
                    <button type="button" class="button button-primary" id="pbe_sync_reviews_now_btn">Sync All Reviews Now</button>
                    <span id="pbe_sync_reviews_status" style="margin-left:10px; font-weight:bold;">
                        <?php 
                        $platform_id = get_option('pbe_active_platform', 'guesty');
                        $offset_key  = 'pbe_review_sync_last_offset_' . $platform_id;
                        $saved_offset = get_option($offset_key, 0);
                        if ($saved_offset > 0) {
                            echo '<span style="color:#2271b1;"><span class="dashicons dashicons-update" style="font-size:18px; vertical-align:middle; margin-right:5px;"></span> Sync Status: ' . $saved_offset . ' properties processed. Click to resume from where you left off.</span>';
                        }
                        ?>
                    </span>
                    <div style="margin-top:10px;">
                        <?php echo $this->get_last_sync_html('review'); ?>
                    </div>
                </div>

                <!-- TAB: Payments -->
                <div id="pbe-tab-payments" class="pbe-tab-content" style="display:none;">
                    <h3>Stripe Payment Integration</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Payment Mode</th>
                            <td>
                                <select name="pbe_stripe_mode">
                                    <option value="test" <?php selected(get_option('pbe_stripe_mode', 'test'), 'test'); ?>>Test Mode</option>
                                    <option value="live" <?php selected(get_option('pbe_stripe_mode'), 'live'); ?>>Live Mode</option>
                                </select>
                                <p class="description">Toggle between Stripe Test and Live environments.</p>
                            </td>
                        </tr>
                        <tr class="pbe-stripe-key-row pbe-stripe-mode-test">
                            <th scope="row">Test Public Key</th>
                            <td><input type="text" name="pbe_stripe_test_pub" value="<?php echo esc_attr(get_option('pbe_stripe_test_pub')); ?>" class="regular-text" placeholder="pk_test_..."></td>
                        </tr>
                        <tr class="pbe-stripe-key-row pbe-stripe-mode-test">
                            <th scope="row">Test Secret Key</th>
                            <td><input type="password" name="pbe_stripe_test_sec" value="<?php echo esc_attr(get_option('pbe_stripe_test_sec')); ?>" class="regular-text" placeholder="sk_test_..."></td>
                        </tr>
                        <tr class="pbe-stripe-key-row pbe-stripe-mode-live">
                            <th scope="row">Live Public Key</th>
                            <td><input type="text" name="pbe_stripe_live_pub" value="<?php echo esc_attr(get_option('pbe_stripe_live_pub')); ?>" class="regular-text" placeholder="pk_live_..."></td>
                        </tr>
                        <tr class="pbe-stripe-key-row pbe-stripe-mode-live">
                            <th scope="row">Live Secret Key</th>
                            <td><input type="password" name="pbe_stripe_live_sec" value="<?php echo esc_attr(get_option('pbe_stripe_live_sec')); ?>" class="regular-text" placeholder="sk_live_..."></td>
                        </tr>
                    </table>
                </div>

                <!-- TAB: Advanced -->
                <div id="pbe-tab-advanced" class="pbe-tab-content" style="display:none;">
                    <h3>Testing & Debugging</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Test Mode</th>
                            <td>
                                <input type="checkbox" name="pbe_test_mode" value="1" <?php checked(get_option('pbe_test_mode'), '1'); ?>>
                                <p class="description">When active, native bookings are **restricted** to the Test Listing only.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Test Listing ID</th>
                            <td>
                                <input type="text" name="pbe_test_listing_id" value="<?php echo esc_attr(get_option('pbe_test_listing_id')); ?>" class="regular-text">
                                <p class="description">The only Platform ID allowed for reservations when Test Mode is ON.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Availability Cache</th>
                            <td>
                                <input type="number" name="pbe_cache_duration" value="<?php echo esc_attr(get_option('pbe_cache_duration', 15)); ?>" class="small-text">
                                <span class="description">Minutes. Set to 0 to disable caching.</span>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div style="margin-top: 30px;">
                    <?php submit_button('Save Settings'); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Helper to format last sync timestamps
     */
    private function get_last_sync_html($sync_type) {
        $platform_id = get_option('pbe_active_platform', 'guesty');
        $option_name = "pbe_last_{$sync_type}_sync_{$platform_id}";
        $timestamp = get_option($option_name);

        if (!$timestamp) {
            return '<span class="description" style="color:#d63638;">Never synced for ' . ucfirst($platform_id) . '.</span>';
        }

        $diff = human_time_diff($timestamp, time());
        $date_formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp + (get_option('gmt_offset') * HOUR_IN_SECONDS));
        
        return sprintf(
            '<span class="description" style="color:#2271b1; font-weight:600;">Last %s Sync: %s ago (%s)</span>',
            ucfirst($sync_type),
            $diff,
            $date_formatted
        );
    }
}
