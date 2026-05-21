<?php
/**
 * Register Custom Post Type and Custom Meta Boxes (No ACF required)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_CPT {

    public function init() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'init', array( $this, 'register_review_post_type' ) );
        add_action( 'init', array( $this, 'register_reservation_post_type' ) );
        add_action( 'init', array( $this, 'register_taxonomies' ) );
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post_property', array( $this, 'save_meta_boxes' ), 10, 2 );
        add_action( 'save_post_pbe_review', array( $this, 'save_review_meta' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_footer', array( $this, 'disable_parent_term_checkboxes' ) );
        add_action( 'admin_footer', array( $this, 'add_row_action_js' ) );
        
        // Custom Admin Columns & Actions
        add_filter( 'post_row_actions', array( $this, 'add_sync_reviews_row_action' ), 10, 2 );
        add_filter( 'manage_property_posts_columns', array( $this, 'set_custom_property_columns' ) );
        add_action( 'manage_property_posts_custom_column', array( $this, 'render_custom_property_columns' ), 10, 2 );
        add_filter( 'manage_pbe_review_posts_columns', array( $this, 'set_custom_review_columns' ) );
        add_action( 'manage_pbe_review_posts_custom_column', array( $this, 'render_custom_review_columns' ), 10, 2 );
        add_filter( 'manage_pbe_reservation_posts_columns', array( $this, 'set_custom_reservation_columns' ) );
        add_action( 'manage_pbe_reservation_posts_custom_column', array( $this, 'render_custom_reservation_columns' ), 10, 2 );
        
        // Count Filtering
        add_filter( 'views_edit-property', array( $this, 'update_admin_list_counts' ) );
        add_filter( 'views_edit-pbe_review', array( $this, 'update_admin_list_counts' ) );
        add_filter( 'views_edit-pbe_reservation', array( $this, 'update_admin_list_counts' ) );
        
        // Term Filtering
        add_filter( 'get_terms', array( $this, 'filter_terms_by_active_platform' ), 10, 4 );
        add_filter( 'terms_clauses', array( $this, 'filter_terms_query_by_platform' ), 10, 3 );

        // Maintain Taxonomy Hierarchy Visually
        add_filter( 'wp_terms_checklist_args', array( $this, 'preserve_taxonomy_hierarchy' ), 10, 2 );

        // Custom search filter for title-only matching
        add_filter( 'posts_search', array( $this, 'search_by_title_only' ), 10, 2 );

        // Filter data by active platform (Global)
        add_action( 'pre_get_posts', array( $this, 'filter_data_by_platform' ) );

        // Admin display polish for hierarchical amenities
        add_action( 'admin_head-edit-tags.php', array( $this, 'hide_parent_amenity_counts' ) );
    }

    /**
     * Filter the search SQL to only match post_title if 'pbe_search_title_only' is set.
     */
    public function search_by_title_only( $search, $wp_query ) {
        if ( ! empty( $search ) && $wp_query->get( 'pbe_search_title_only' ) ) {
            global $wpdb;
            $q = $wp_query->query_vars;
            $n = ! empty( $q['exact'] ) ? '' : '%';
            $search = $wpdb->prepare( 
                " AND ({$wpdb->posts}.post_title LIKE %s)", 
                $n . $wpdb->esc_like( $q['s'] ) . $n 
            );
        }
        return $search;
    }

    /**
     * Prevents checked terms from flying to the top of the checklist
     */
    public function preserve_taxonomy_hierarchy( $args, $post_id ) {
        if ( isset($args['taxonomy']) && in_array($args['taxonomy'], array('amenity', 'property_tag')) ) {
            $args['checked_ontop'] = false;
        }
        return $args;
    }

    /**
     * Add "Sync Reviews" link to property list row actions
     */
    public function add_sync_reviews_row_action( $actions, $post ) {
        if ( $post->post_type === 'property' ) {
            $actions['pbe_sync_reviews'] = sprintf(
                '<a href="#" class="pbe-sync-row-action" data-post-id="%d" style="color:#196EE6; font-weight:600;">%s</a>',
                $post->ID,
                'Sync Reviews'
            );
        }
        return $actions;
    }

    /**
     * JS for the Row Action click
     */
    public function add_row_action_js() {
        $screen = get_current_screen();
        if ( ! $screen ) return;

        // Property List Sync JS
        if ( $screen->post_type === 'property' && $screen->base === 'edit' ) {
            ?>
            <script>
            jQuery(document).ready(function($){
                $(document).on('click', '.pbe-sync-row-action', function(e){
                    e.preventDefault();
                    var link = $(this);
                    var originalText = link.text();
                    var postId = link.data('post-id');

                    link.text('Syncing...').css('opacity', '0.6').css('pointer-events', 'none');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'pbe_sync_single_property_reviews',
                            post_id: postId,
                            security: '<?php echo wp_create_nonce("pbe_sync_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                link.text('✅ Done').css('color', '#207b4d').css('opacity', '1');
                                setTimeout(function(){
                                    link.text(originalText).css('color', '#196EE6').css('pointer-events', 'auto');
                                }, 3000);
                            } else {
                                alert('Sync failed: ' + response.data);
                                link.text(originalText).css('opacity', '1').css('pointer-events', 'auto');
                            }
                        },
                        error: function() {
                            alert('Network error.');
                            link.text(originalText).css('opacity', '1').css('pointer-events', 'auto');
                        }
                    });
                });
            });
            </script>
            <?php
        }
    }

    public function set_custom_property_columns( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $title ) {
            if ( $key == 'author' ) {
                $new_columns['platform']    = 'Platform';
                $new_columns['platform_id'] = 'Platform ID';
                $new_columns['is_active']   = 'Status';
                $new_columns['last_sync']   = 'Last Sync';
            }
            $new_columns[$key] = $title;
        }
        return $new_columns;
    }

    public function render_custom_property_columns( $column, $post_id ) {
        if ( $column == 'platform' ) {
            $source = get_post_meta( $post_id, 'platform_source', true );
            echo $source ? esc_html( ucfirst( $source ) ) : '—';
        }
        if ( $column == 'platform_id' ) {
            $pid = get_post_meta( $post_id, 'platform_property_id', true );
            echo $pid ? esc_html($pid) : '—';
        }
        if ( $column == 'is_active' ) {
            $active = get_post_meta( $post_id, 'is_active', true );
            echo ( $active === '1' ) ? 'Active' : 'Inactive';
        }
        if ( $column == 'last_sync' ) {
            $last_sync = get_post_meta( $post_id, '_pbe_last_sync_time', true );
            if ( $last_sync ) {
                $time_diff = human_time_diff( $last_sync, time() );
                // Add gmt_offset to display correct local time in tooltip
                $full_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
                echo '<span title="' . esc_attr( $full_date ) . '" style="cursor:help; border-bottom:1px dotted #666;">' . sprintf( esc_html__( '%s ago', 'pbe' ), $time_diff ) . '</span>';
            } else {
                echo '<span style="color:#d63638; font-weight:600;">Never</span>';
            }
        }
    }

    public function set_custom_review_columns( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $title ) {
            if ( $key == 'date' ) {
                $new_columns['property'] = 'Property';
                $new_columns['rating']   = 'Rating';
            }
            $new_columns[$key] = $title;
        }
        return $new_columns;
    }

    public function render_custom_review_columns( $column, $post_id ) {
        if ( $column == 'property' ) {
            $parent_id = wp_get_post_parent_id( $post_id );
            if ( $parent_id ) {
                echo '<a href="' . get_edit_post_link( $parent_id ) . '">' . get_the_title( $parent_id ) . '</a>';
            } else {
                echo '—';
            }
        }
        if ( $column == 'rating' ) {
            $rating = get_post_meta( $post_id, 'pbe_rating', true );
            echo $rating ? '⭐ ' . esc_html( $rating ) : '—';
        }
    }

    public function set_custom_reservation_columns( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $title ) {
            if ( $key == 'date' ) {
                $new_columns['guest']    = 'Guest';
                $new_columns['property'] = 'Property';
                $new_columns['stay']     = 'Stay Dates';
                $new_columns['total']    = 'Total';
                $new_columns['status']   = 'Status';
            }
            $new_columns[$key] = $title;
        }
        return $new_columns;
    }

    public function render_custom_reservation_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'guest':
                $email = get_post_meta( $post_id, 'pbe_guest_email', true );
                echo '<strong>' . get_the_title( $post_id ) . '</strong><br>';
                echo '<small>' . esc_html($email) . '</small>';
                break;
            case 'property':
                $prop_id = get_post_meta( $post_id, 'pbe_property_id', true );
                if ( $prop_id ) {
                    echo '<a href="' . get_edit_post_link( $prop_id ) . '">' . get_the_title( $prop_id ) . '</a>';
                }
                break;
            case 'stay':
                $start = get_post_meta( $post_id, 'pbe_checkin', true );
                $end   = get_post_meta( $post_id, 'pbe_checkout', true );
                echo esc_html( $start . ' to ' . $end );
                break;
            case 'total':
                echo '$' . number_format( (float) get_post_meta( $post_id, 'pbe_total_price', true ), 2 );
                break;
            case 'status':
                $status = get_post_meta( $post_id, 'pbe_status', true );
                echo '<span class="pbe-badge pbe-status-' . esc_attr($status) . '">' . esc_html( ucfirst($status) ) . '</span>';
                break;
    }
    }



    public function register_taxonomies() {
        register_taxonomy('amenity', 'property', array(
            'label'        => 'Amenities',
            'rewrite'      => array('slug' => 'amenity'),
            'hierarchical' => true,
            'show_admin_column' => false,
            'show_in_rest' => true
        ));

        register_taxonomy('property_tag', 'property', array(
            'label'        => 'Tags',
            'rewrite'      => array('slug' => 'property-tag'),
            'hierarchical' => true,
            'show_admin_column' => false,
            'show_in_rest' => true
        ));
    }

    public function register_post_type() {
        $labels = array(
            'name'               => 'Properties',
            'singular_name'      => 'Property',
            'menu_name'          => 'Properties',
            'name_admin_bar'     => 'Property',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Property',
            'new_item'           => 'New Property',
            'edit_item'          => 'Edit Property',
            'view_item'          => 'View Property',
            'all_items'          => 'All Properties',
            'search_items'       => 'Search Properties',
            'not_found'          => 'No properties found.',
            'not_found_in_trash' => 'No properties found in Trash.',
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'property' ),
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => null,
            'menu_icon'          => 'dashicons-building',
            'supports'           => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt' ),
            'show_in_rest'       => true,
        );

        register_post_type( 'property', $args );
    }
    
    public function register_review_post_type() {
        $labels = array(
            'name'               => 'Reviews',
            'singular_name'      => 'Review',
            'menu_name'          => 'Reviews',
            'add_new'            => 'Add New Review',
            'edit_item'          => 'Edit Review',
            'all_items'          => 'All Reviews',
            'search_items'       => 'Search Reviews',
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=property', // Nest under Properties
            'supports'           => array( 'title', 'editor' ),
            'hierarchical'       => false,
            'capability_type'    => 'post',
        );

        register_post_type( 'pbe_review', $args );
    }

    public function register_reservation_post_type() {
        $labels = array(
            'name'               => 'Reservations',
            'singular_name'      => 'Reservation',
            'menu_name'          => 'Reservations',
            'add_new'            => 'Add New Reservation',
            'edit_item'          => 'Edit Reservation',
            'all_items'          => 'All Reservations',
            'search_items'       => 'Search Reservations',
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=property', // Nest under Properties
            'supports'           => array( 'title' ),
            'hierarchical'       => false,
            'capability_type'    => 'post',
            'menu_icon'          => 'dashicons-calendar-alt',
        );

        register_post_type( 'pbe_reservation', $args );
    }

    /**
     * Register all custom meta boxes for the Property post type.
     */
    public function register_meta_boxes() {
        add_meta_box(
            'pbe_property_pricing',
            'Pricing & Capacity',
            array( $this, 'render_pricing_meta_box' ),
            'property',
            'normal',
            'high'
        );

        add_meta_box(
            'pbe_property_location',
            'Location Details',
            array( $this, 'render_location_meta_box' ),
            'property',
            'normal',
            'high'
        );

        add_meta_box(
            'pbe_property_gallery',
            'Gallery & Images',
            array( $this, 'render_gallery_meta_box' ),
            'property',
            'normal',
            'default'
        );

        add_meta_box(
            'pbe_property_platform',
            '🔗 Platform / Sync Info',
            array( $this, 'render_platform_meta_box' ),
            'property',
            'side',
            'default'
        );

        add_meta_box(
            'pbe_property_extra_details',
            'House Rules & Extra Details',
            array( $this, 'render_extra_details_meta_box' ),
            'property',
            'normal',
            'default'
        );

        add_meta_box(
            'pbe_property_sync_actions',
            '⚡ Quick Sync Actions',
            array( $this, 'render_sync_actions_meta_box' ),
            'property',
            'side',
            'high'
        );

        // Reservation Specific Meta Boxes
        add_meta_box(
            'pbe_reservation_details',
            'Reservation Details',
            array( $this, 'render_reservation_meta_box' ),
            'pbe_reservation',
            'normal',
            'high'
        );
    }

    public function enqueue_admin_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, array( 'property', 'pbe_reservation' ) ) ) {
            return;
        }
        
        // Let's add standard styles here
        wp_add_inline_style( 'wp-admin', '
            .pbe-meta-description { color: #50575e; font-size: 13px; margin: 0 0 12px; }
            .pbe-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
            .pbe-meta-field label { display: block; font-weight: 600; margin-bottom: 5px; }
            .pbe-meta-field input[type="text"], 
            .pbe-meta-field input[type="number"], 
            .pbe-meta-field select { width: 100%; border-radius: 4px; padding: 4px 8px; }
            .pbe-badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
            .pbe-status-confirmed { background: #e7f6ed; color: #207b4d; }
            .pbe-status-pending { background: #fff8e5; color: #856404; }
            .pbe-status-canceled { background: #fbeaea; color: #af1e1e; }
        ' );

        wp_enqueue_style(
            'pbe-admin-css',
            PBE_PLUGIN_URL . 'assets/css/pbe-admin.css',
            array(),
            '2.0.0'
        );

    }

    /**
     * Disables parent taxonomy checkboxes so only granular children can be selected.
     */
    public function disable_parent_term_checkboxes() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'property' ) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($){
            function disableParentTaxonomies() {
                $('#amenitydiv .categorychecklist > li:has(ul.children) > label > input[type="checkbox"]').prop('disabled', true);
                $('#property_tagdiv .categorychecklist > li:has(ul.children) > label > input[type="checkbox"]').prop('disabled', true);
            }
            
            // Run on load
            disableParentTaxonomies();
            
            // Also run after AJAX taxonomy additions
            $(document).ajaxComplete(function(event, xhr, settings) {
                if (settings.data && settings.data.indexOf('action=add-term') !== -1) {
                    disableParentTaxonomies();
                }
            });
        });
        </script>
        <?php
    }


    // ─────────────────────────────────────────────
    // META BOX RENDERERS
    // ─────────────────────────────────────────────


    public function render_pricing_meta_box( $post ) {
        wp_nonce_field( 'pbe_save_meta', 'pbe_meta_nonce' );

        $price     = get_post_meta( $post->ID, 'price_per_night', true );
        $bedrooms  = get_post_meta( $post->ID, 'bedrooms', true );
        $bathrooms = get_post_meta( $post->ID, 'bathrooms', true );
        $guests    = get_post_meta( $post->ID, 'max_guests', true );
        $type      = get_post_meta( $post->ID, 'property_type', true );
        $room_type = get_post_meta( $post->ID, 'room_type', true );
        $sqft      = get_post_meta( $post->ID, 'area_square_feet', true );
        ?>
        <p class="pbe-meta-description">Core pricing and accommodation details for this property.</p>
        <div class="pbe-meta-grid">
            <div class="pbe-meta-field">
                <label for="pbe_price_per_night">Price Per Night ($)</label>
                <input type="number" id="pbe_price_per_night" name="pbe_price_per_night"
                       value="<?php echo esc_attr( $price ); ?>"
                       placeholder="e.g. 150" min="0" step="0.01">
            </div>
            <div class="pbe-meta-field">
                <label for="pbe_bedrooms">Bedrooms</label>
                <input type="number" id="pbe_bedrooms" name="pbe_bedrooms"
                       value="<?php echo esc_attr( $bedrooms ); ?>"
                       placeholder="e.g. 3" min="0">
            </div>
            <div class="pbe-meta-field">
                <label for="pbe_bathrooms">Bathrooms</label>
                <input type="number" id="pbe_bathrooms" name="pbe_bathrooms"
                       value="<?php echo esc_attr( $bathrooms ); ?>"
                       placeholder="e.g. 2" min="0" step="0.5">
            </div>
            <div class="pbe-meta-field">
                <label for="pbe_max_guests">Max Guests</label>
                <input type="number" id="pbe_max_guests" name="pbe_max_guests"
                       value="<?php echo esc_attr( $guests ); ?>"
                       placeholder="e.g. 6" min="1">
            </div>
            <div class="pbe-meta-field">
                <label for="pbe_property_type">Property Type</label>
                <select id="pbe_property_type" name="pbe_property_type">
                    <?php
                    $types = array( '' => '— Select —', 'Apartment' => 'Apartment', 'Villa' => 'Villa', 'House' => 'House', 'Condo' => 'Condo', 'Cabin' => 'Cabin', 'Cottage' => 'Cottage', 'Bungalow' => 'Bungalow', 'Other' => 'Other' );
                    foreach ( $types as $val => $label ) {
                        printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $type, $val, false ), esc_html( $label ) );
                    }
                    ?>
                </select>
            </div>
            <div class="pbe-meta-field">
                <label for="pbe_room_type">Room Type</label>
                <input type="text" id="pbe_room_type" name="pbe_room_type"
                       value="<?php echo esc_attr( $room_type ); ?>"
                       placeholder="e.g. Entire home/apt">
            </div>
            <div class="pbe-meta-field">
                <label for="pbe_area_square_feet">Area (Sq Ft)</label>
                <input type="text" id="pbe_area_square_feet" name="pbe_area_square_feet"
                       value="<?php echo esc_attr( $sqft ); ?>"
                       placeholder="e.g. 1500">
            </div>
        </div>
        <?php
    }

    public function render_extra_details_meta_box( $post ) {
        $is_active   = get_post_meta( $post->ID, 'is_active', true );
        $is_listed   = get_post_meta( $post->ID, 'is_listed', true );
        $house_rules = get_post_meta( $post->ID, 'house_rules', true );
        ?>
        <p class="pbe-meta-description">Additional system and listing details (often synced from APIs).</p>
        <div class="pbe-meta-grid">
            <div class="pbe-meta-field" style="display:flex; flex-direction:column; justify-content:center;">
                <label>
                    <input type="checkbox" name="pbe_is_active" value="1" <?php checked($is_active, '1'); ?>> Active / Enabled
                </label>
                <label style="margin-top:8px;">
                    <input type="checkbox" name="pbe_is_listed" value="1" <?php checked($is_listed, '1'); ?>> Listed / Published
                </label>
            </div>
        </div>
        <div class="pbe-meta-field" style="margin-top:10px;">
            <label for="pbe_house_rules">House Rules</label>
            <textarea id="pbe_house_rules" name="pbe_house_rules" rows="4" style="width:100%;"><?php echo esc_textarea( $house_rules ); ?></textarea>
        </div>
        <?php
    }

    public function render_location_meta_box( $post ) {
        $address  = get_post_meta( $post->ID, 'full_address', true );
        $street   = get_post_meta( $post->ID, 'street', true );
        $address2 = get_post_meta( $post->ID, 'address2', true );
        $city     = get_post_meta( $post->ID, 'city', true );
        $state    = get_post_meta( $post->ID, 'state', true );
        $country  = get_post_meta( $post->ID, 'country', true );
        $zip      = get_post_meta( $post->ID, 'zip', true );
        $lat     = get_post_meta( $post->ID, 'latitude', true );
        $lng     = get_post_meta( $post->ID, 'longitude', true );
        ?>
        <p class="pbe-meta-description">Address and map coordinates for this property.</p>
        <div class="pbe-meta-grid" style="grid-template-columns: 1fr;">
            <div class="pbe-meta-field">
                <label for="pbe_full_address">Full Address</label>
                <input type="text" id="pbe_full_address" name="pbe_full_address"
                       value="<?php echo esc_attr( $address ); ?>"
                       placeholder="e.g. 123 Main St, Miami, FL 33101, USA">
            </div>
        </div>
        <div class="pbe-meta-grid">
            <div class="pbe-meta-field"><label>Street</label><input type="text" name="pbe_street" value="<?php echo esc_attr($street); ?>"></div>
            <div class="pbe-meta-field"><label>Address 2</label><input type="text" name="pbe_address2" value="<?php echo esc_attr($address2); ?>"></div>
            <div class="pbe-meta-field"><label>City</label><input type="text" name="pbe_city" value="<?php echo esc_attr($city); ?>"></div>
            <div class="pbe-meta-field"><label>State</label><input type="text" name="pbe_state" value="<?php echo esc_attr($state); ?>"></div>
            <div class="pbe-meta-field"><label>Zip</label><input type="text" name="pbe_zip" value="<?php echo esc_attr($zip); ?>"></div>
            <div class="pbe-meta-field"><label>Country</label><input type="text" name="pbe_country" value="<?php echo esc_attr($country); ?>"></div>
            <div class="pbe-meta-field">
                <label for="pbe_latitude">Latitude</label>
                <input type="text" id="pbe_latitude" name="pbe_latitude"
                       value="<?php echo esc_attr( $lat ); ?>"
                       placeholder="e.g. 25.7617">
            </div>
            <div class="pbe-meta-field">
                <label for="pbe_longitude">Longitude</label>
                <input type="text" id="pbe_longitude" name="pbe_longitude"
                       value="<?php echo esc_attr( $lng ); ?>"
                       placeholder="e.g. -80.1918">
            </div>
        </div>
        <?php
    }

    public function render_gallery_meta_box( $post ) {
        $feat_url    = get_post_meta( $post->ID, 'featured_image_url', true );
        $gallery_raw = get_post_meta( $post->ID, 'property_gallery_urls', true );
        $gallery     = $gallery_raw ? json_decode( $gallery_raw, true ) : array();
        ?>
        <p class="pbe-meta-description">Gallery URLs are synced automatically from the platform. You can also set a featured image URL manually.</p>
        <div class="pbe-meta-grid" style="grid-template-columns: 1fr;">
            <div class="pbe-meta-field">
                <label for="pbe_featured_image_url">Featured Image URL</label>
                <input type="url" id="pbe_featured_image_url" name="pbe_featured_image_url"
                       value="<?php echo esc_attr( $feat_url ); ?>"
                       placeholder="https://example.com/image.jpg">
            </div>
        </div>

        <?php if ( ! empty( $gallery ) && is_array( $gallery ) ) : ?>
            <p style="font-size:12px; font-weight:600; color:#1d2327; margin:14px 0 6px;">
                Gallery Preview <span style="font-weight:400; color:#646970;">(<?php echo count( $gallery ); ?> images — synced from platform)</span>
            </p>
            <div class="pbe-gallery-preview">
                <?php foreach ( array_slice( $gallery, 0, 12 ) as $url ) : ?>
                    <img src="<?php echo esc_url( $url ); ?>" alt="Gallery image" loading="lazy">
                <?php endforeach; ?>
                <?php if ( count( $gallery ) > 12 ) : ?>
                    <div style="width:80px;height:60px;background:#f0f0f0;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#646970;border:1px solid #c3c4c7;">
                        +<?php echo count( $gallery ) - 12; ?> more
                    </div>
                <?php endif; ?>
            </div>
            <p class="pbe-readonly-notice">Gallery URLs are managed by the sync engine. Edit them in JSON below if needed.</p>
            <div class="pbe-meta-field" style="margin-top:10px;">
                <label for="pbe_gallery_urls_json">Gallery URLs (JSON — advanced)</label>
                <textarea id="pbe_gallery_urls_json" name="pbe_gallery_urls_json" rows="4"
                    style="font-family:monospace;font-size:12px;"><?php echo esc_textarea( $gallery_raw ); ?></textarea>
            </div>
        <?php else : ?>
            <p style="font-size:12px; color:#646970;">No gallery images yet. Sync a property from a platform to populate the gallery automatically.</p>
            <div class="pbe-meta-field" style="margin-top:10px;">
                <label for="pbe_gallery_urls_json">Gallery URLs (JSON — advanced)</label>
                <textarea id="pbe_gallery_urls_json" name="pbe_gallery_urls_json" rows="4"
                    style="font-family:monospace;font-size:12px;" placeholder='["https://...","https://..."]'><?php echo esc_textarea( $gallery_raw ); ?></textarea>
            </div>
        <?php endif; ?>
        <?php
    }

    public function render_platform_meta_box( $post ) {
        $source      = get_post_meta( $post->ID, 'platform_source', true );
        $platform_id = get_post_meta( $post->ID, 'platform_property_id', true );
        ?>
        <div class="pbe-platform-row">
            <strong>Platform</strong>
            <span><?php echo $source ? esc_html( ucfirst( $source ) ) : '—'; ?></span>
        </div>
        <div class="pbe-platform-row">
            <strong>Platform ID</strong>
            <span title="<?php echo esc_attr( $platform_id ); ?>"><?php echo $platform_id ? esc_html( $platform_id ) : '—'; ?></span>
        </div>
        <p class="pbe-readonly-notice">These values are managed by the sync engine and are read-only.</p>
        <?php
    }

    public function render_sync_actions_meta_box( $post ) {
        $platform_id = get_post_meta($post->ID, 'platform_property_id', true);
        $last_sync = get_post_meta($post->ID, '_pbe_last_sync_time', true);
        ?>
        <div class="pbe-sync-sidebar-box">
            <p style="margin-top:0; font-size:12px; color:#646970;">Force a manual refresh for this property.</p>
            
            <button type="button" class="button button-secondary pbe-sync-reviews-btn" 
                    data-post-id="<?php echo $post->ID; ?>" 
                    style="width:100%; margin-bottom:10px; display:flex; align-items:center; justify-content:center; gap:5px;">
                <span class="dashicons dashicons-testimonial" style="font-size:16px; width:16px; height:16px;"></span>
                Sync Reviews
            </button>

            <div id="pbe-property-sync-msg" style="margin:10px 0; font-weight:600; font-size:12px; display:none;"></div>

            <?php if ( $last_sync ) : ?>
                <div style="font-size:11px; color:#646970; border-top:1px solid #eee; padding-top:10px;">
                    <strong>Last Property Sync:</strong><br>
                    <?php echo human_time_diff( $last_sync, time() ); ?> ago
                </div>
            <?php endif; ?>
        </div>
        <script>
        jQuery(document).ready(function($){
            $('.pbe-sync-reviews-btn').on('click', function(){
                var btn = $(this);
                var msg = $('#pbe-property-sync-msg');
                var postId = btn.data('post-id');

                btn.prop('disabled', true).text('Syncing...');
                msg.show().text('🔄 Connecting to Platform...').css('color', '#2271b1');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'pbe_sync_single_property_reviews',
                        post_id: postId,
                        security: '<?php echo wp_create_nonce("pbe_sync_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            btn.text('Sync Reviews');
                            msg.text('✅ Reviews Updated!').css('color', '#207b4d');
                        } else {
                            msg.text('❌ Error: ' + response.data).css('color', '#d63638');
                        }
                    },
                    error: function() {
                        msg.text('❌ Network Error.').css('color', '#d63638');
                    },
                    complete: function() {
                        btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function render_reservation_meta_box( $post ) {
        $res_title    = get_the_title( $post->ID );
        $prop_id      = get_post_meta( $post->ID, 'pbe_property_id', true );
        $checkin      = get_post_meta( $post->ID, 'pbe_checkin', true );
        $checkout     = get_post_meta( $post->ID, 'pbe_checkout', true );
        $guests       = get_post_meta( $post->ID, 'pbe_guests', true );
        $email        = get_post_meta( $post->ID, 'pbe_guest_email', true );
        $phone        = get_post_meta( $post->ID, 'pbe_guest_phone', true );
        $total        = get_post_meta( $post->ID, 'pbe_total_price', true );
        $status       = get_post_meta( $post->ID, 'pbe_status', true );
        $platform     = get_post_meta( $post->ID, 'pbe_platform', true );
        $platform_res = get_post_meta( $post->ID, 'pbe_platform_res_id', true );
        $stripe_id    = get_post_meta( $post->ID, 'pbe_stripe_intent_id', true );
        $receipt_url  = get_post_meta( $post->ID, 'pbe_stripe_receipt_url', true );
        $is_test      = get_post_meta( $post->ID, 'pbe_is_test', true );

        $prop_title = $prop_id ? get_the_title( $prop_id ) : 'Unknown Property';
        ?>
        <div class="pbe-meta-grid">
            <div class="pbe-meta-field">
                <label>Property</label>
                <div class="pbe-readonly-field">
                    <strong><?php echo esc_html($prop_title); ?></strong>
                    <?php if ($prop_id) : ?>
                        <br><a href="<?php echo get_edit_post_link($prop_id); ?>" target="_blank">View Property &rsaquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pbe-meta-field">
                <label>Status</label>
                <div class="pbe-readonly-field">
                    <span class="pbe-badge pbe-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                    <?php if ($is_test === '1') : ?>
                        <span style="color:#af1e1e; font-weight:bold; font-size:11px; margin-left:5px;">(TEST BOOKING)</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pbe-meta-field">
                <label>Check-in</label>
                <input type="text" value="<?php echo esc_attr($checkin); ?>" readonly>
            </div>
            <div class="pbe-meta-field">
                <label>Check-out</label>
                <input type="text" value="<?php echo esc_attr($checkout); ?>" readonly>
            </div>
            <div class="pbe-meta-field">
                <label>Total Paid</label>
                <input type="text" value="$<?php echo number_format((float)$total, 2); ?>" readonly style="font-weight:bold; color:#207b4d;">
            </div>
            <div class="pbe-meta-field">
                <label>Guests</label>
                <input type="text" value="<?php echo esc_attr($guests); ?> Guests" readonly>
            </div>
        </div>

        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">

        <h3 style="font-size:14px; margin-bottom:15px;">Guest Contact Information</h3>
        <div class="pbe-meta-grid">
            <div class="pbe-meta-field">
                <label>Guest Name</label>
                <input type="text" value="<?php echo esc_attr($res_title); ?>" readonly>
            </div>
            <div class="pbe-meta-field">
                <label>Email Address</label>
                <input type="text" value="<?php echo esc_attr($email); ?>" readonly>
            </div>
            <div class="pbe-meta-field">
                <label>Phone Number</label>
                <input type="text" value="<?php echo esc_attr($phone); ?>" readonly>
            </div>
        </div>

        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">

        <h3 style="font-size:14px; margin-bottom:15px;">Platform & Payment Details</h3>
        <div class="pbe-meta-grid">
            <div class="pbe-meta-field">
                <label>Platform</label>
                <input type="text" value="<?php echo esc_attr(ucfirst($platform)); ?>" readonly>
            </div>
            <div class="pbe-meta-field">
                <label>External Reservation ID</label>
                <input type="text" value="<?php echo esc_attr($platform_res); ?>" readonly style="font-family:monospace;">
            </div>
            <div class="pbe-meta-field">
                <label>Stripe Payment ID</label>
                <input type="text" value="<?php echo esc_attr($stripe_id); ?>" readonly style="font-family:monospace;">
            </div>
            <div class="pbe-meta-field">
                <label>Stripe Receipt</label>
                <?php if ($receipt_url) : ?>
                    <a href="<?php echo esc_url($receipt_url); ?>" target="_blank" class="button">View Receipt Online</a>
                <?php else : ?>
                    <span style="color:#666; font-style:italic;">No receipt available</span>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top:20px; background:#f9f9f9; padding:10px; border-radius:4px; border-left:4px solid #196EE6; font-size:12px; color:#666;">
            <strong>Note:</strong> These records are created automatically by the booking system. Changes made here do not sync back to Guesty.
        </div>
        <style>
            .pbe-readonly-field { background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 4px; padding: 6px 10px; }
            .pbe-readonly-field strong { color: #1d2327; }
        </style>
        <?php
    }
    
    public function render_review_meta_box( $post ) {
        $rating = get_post_meta( $post->ID, 'pbe_rating', true );
        $title  = get_post_meta( $post->ID, 'pbe_review_title', true );
        $source = get_post_meta( $post->ID, 'pbe_source', true );
        $parent = $post->post_parent;
        
        // Fetch properties for parent dropdown
        $properties = get_posts(array('post_type' => 'property', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        ?>
        <div class="pbe-meta-grid">
            <div class="pbe-meta-field">
                <label>Review Title (Synced)</label>
                <input type="text" name="pbe_review_title" value="<?php echo esc_attr($title); ?>" class="widefat">
            </div>
            <div class="pbe-meta-field">
                <label>Rating (1-5)</label>
                <input type="number" name="pbe_rating" value="<?php echo esc_attr($rating); ?>" min="1" max="10" step="0.1">
                <p class="description">Note: Guesty ratings are usually 1-5, but some platforms like Booking.com use 1-10.</p>
            </div>
            <div class="pbe-meta-field">
                <label>Platform Source</label>
                <input type="text" name="pbe_source" value="<?php echo esc_attr($source); ?>" placeholder="e.g. Airbnb, Guesty, Direct">
            </div>
            <div class="pbe-meta-field">
                <label>Assigned to Property</label>
                <select name="parent_id">
                    <option value="0">— Select Property —</option>
                    <?php foreach ($properties as $prop) : ?>
                        <option value="<?php echo $prop->ID; ?>" <?php selected($parent, $prop->ID); ?>><?php echo esc_html($prop->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    // SAVE HANDLER
    // ─────────────────────────────────────────────

    public function save_meta_boxes( $post_id, $post ) {
        // Security checks
        if ( ! isset( $_POST['pbe_meta_nonce'] ) || ! wp_verify_nonce( $_POST['pbe_meta_nonce'], 'pbe_save_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // ── Pricing & Capacity ──
        if ( isset( $_POST['pbe_price_per_night'] ) ) {
            update_post_meta( $post_id, 'price_per_night', floatval( $_POST['pbe_price_per_night'] ) );
        }
        if ( isset( $_POST['pbe_bedrooms'] ) ) {
            update_post_meta( $post_id, 'bedrooms', intval( $_POST['pbe_bedrooms'] ) );
        }
        if ( isset( $_POST['pbe_bathrooms'] ) ) {
            update_post_meta( $post_id, 'bathrooms', floatval( $_POST['pbe_bathrooms'] ) );
        }
        if ( isset( $_POST['pbe_max_guests'] ) ) {
            update_post_meta( $post_id, 'max_guests', intval( $_POST['pbe_max_guests'] ) );
        }
        if ( isset( $_POST['pbe_property_type'] ) ) {
            update_post_meta( $post_id, 'property_type', sanitize_text_field( $_POST['pbe_property_type'] ) );
        }
        if ( isset( $_POST['pbe_room_type'] ) ) update_post_meta( $post_id, 'room_type', sanitize_text_field( $_POST['pbe_room_type'] ) );
        if ( isset( $_POST['pbe_area_square_feet'] ) ) update_post_meta( $post_id, 'area_square_feet', sanitize_text_field( $_POST['pbe_area_square_feet'] ) );

        // ── Extra Details ──
        if ( isset( $_POST['pbe_house_rules'] ) ) update_post_meta( $post_id, 'house_rules', wp_kses_post( $_POST['pbe_house_rules'] ) );
        update_post_meta( $post_id, 'is_active', isset( $_POST['pbe_is_active'] ) ? '1' : '0' );
        update_post_meta( $post_id, 'is_listed', isset( $_POST['pbe_is_listed'] ) ? '1' : '0' );

        // ── Location ──
        if ( isset( $_POST['pbe_full_address'] ) ) {
            update_post_meta( $post_id, 'full_address', sanitize_text_field( $_POST['pbe_full_address'] ) );
        }
        if ( isset( $_POST['pbe_latitude'] ) ) {
            update_post_meta( $post_id, 'latitude', sanitize_text_field( $_POST['pbe_latitude'] ) );
        }
        if ( isset( $_POST['pbe_longitude'] ) ) {
            update_post_meta( $post_id, 'longitude', sanitize_text_field( $_POST['pbe_longitude'] ) );
        }
        if ( isset( $_POST['pbe_street'] ) ) update_post_meta( $post_id, 'street', sanitize_text_field( $_POST['pbe_street'] ) );
        if ( isset( $_POST['pbe_address2'] ) ) update_post_meta( $post_id, 'address2', sanitize_text_field( $_POST['pbe_address2'] ) );
        if ( isset( $_POST['pbe_city'] ) ) update_post_meta( $post_id, 'city', sanitize_text_field( $_POST['pbe_city'] ) );
        if ( isset( $_POST['pbe_state'] ) ) update_post_meta( $post_id, 'state', sanitize_text_field( $_POST['pbe_state'] ) );
        if ( isset( $_POST['pbe_country'] ) ) update_post_meta( $post_id, 'country', sanitize_text_field( $_POST['pbe_country'] ) );
        if ( isset( $_POST['pbe_zip'] ) ) update_post_meta( $post_id, 'zip', sanitize_text_field( $_POST['pbe_zip'] ) );

        // ── Gallery ──
        if ( isset( $_POST['pbe_featured_image_url'] ) ) {
            update_post_meta( $post_id, 'featured_image_url', esc_url_raw( $_POST['pbe_featured_image_url'] ) );
        }
        if ( isset( $_POST['pbe_gallery_urls_json'] ) ) {
            $decoded = json_decode( stripslashes( $_POST['pbe_gallery_urls_json'] ), true );
            if ( is_array( $decoded ) ) {
                update_post_meta( $post_id, 'property_gallery_urls', wp_json_encode( array_map( 'esc_url_raw', $decoded ) ) );
            }
        }
    }

    /**
     * Review saving handles ratings and property assignment
     */
    public function save_review_meta( $post_id ) {
        if ( ! isset( $_POST['pbe_meta_nonce'] ) || ! wp_verify_nonce( $_POST['pbe_meta_nonce'], 'pbe_save_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( isset( $_POST['pbe_rating'] ) ) {
            update_post_meta( $post_id, 'pbe_rating', sanitize_text_field( $_POST['pbe_rating'] ) );
        }
        if ( isset( $_POST['pbe_review_title'] ) ) {
            update_post_meta( $post_id, 'pbe_review_title', sanitize_text_field( $_POST['pbe_review_title'] ) );
        }
        if ( isset( $_POST['pbe_source'] ) ) {
            update_post_meta( $post_id, 'pbe_source', sanitize_text_field( $_POST['pbe_source'] ) );
        }
        if ( isset( $_POST['parent_id'] ) ) {
            global $wpdb;
            $wpdb->update($wpdb->posts, array('post_parent' => intval($_POST['parent_id'])), array('ID' => $post_id));
        }
    }

    /**
     * Filter data (Admin & Frontend) to only show data from the active platform
     */
    public function filter_data_by_platform( $query ) {
        if ( ! $query->is_main_query() ) {
            return;
        }

        // Allow singular property requests to pass through so the Template Loader can handle redirects
        if ( ! is_admin() && ( $query->is_singular( 'property' ) || ( $query->get( 'post_type' ) === 'property' && $query->get( 'name' ) ) ) ) {
            return;
        }

        $active_platform = get_option( 'pbe_active_platform', 'guesty' );
        $post_type = $query->get( 'post_type' );

        // In Admin, we use get_current_screen()
        if ( is_admin() ) {
            $screen = get_current_screen();
            if ( ! $screen || $screen->base !== 'edit' ) {
                return;
            }
            $post_type = $screen->post_type;
        }

        $allowed_types = array( 'property', 'pbe_review', 'pbe_reservation' );
        
        // Ensure we are filtering one of our allowed types
        $match = false;
        if ( is_array( $post_type ) ) {
            $intersect = array_intersect( $post_type, $allowed_types );
            $match = ! empty( $intersect );
        } else {
            $match = in_array( $post_type, $allowed_types );
        }

        if ( ! $match ) {
            return;
        }

        $meta_query = $query->get( 'meta_query' ) ?: array();
        
        // Meta key mapping
        $current_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
        $key = ( $current_type === 'pbe_reservation' ) ? 'pbe_platform' : 'platform_source';
        if ( $current_type === 'pbe_review' ) $key = 'pbe_platform_source';

        $meta_query[] = array(
            'key'     => $key,
            'value'   => $active_platform,
            'compare' => '=',
        );
        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Updates the counts (All, Published, etc) at the top of the admin list
     */
    public function update_admin_list_counts( $views ) {
        $screen = get_current_screen();
        if ( ! $screen ) return $views;

        $post_type = $screen->post_type;
        $active_platform = get_option( 'pbe_active_platform', 'guesty' );

        return $this->refine_admin_views( $views, $post_type, $active_platform );
    }

    /**
     * Helper to refine admin views (All, Published, etc) and remove "Mine" to prevent confusion.
     */
    private function refine_admin_views( $views, $post_type, $active_platform ) {
        // Remove "Mine" as it often causes confusion with platform isolation
        if ( isset( $views['mine'] ) ) {
            unset( $views['mine'] );
        }

        $meta_key = ( $post_type === 'pbe_reservation' ) ? 'pbe_platform' : 'platform_source';
        if ( $post_type === 'pbe_review' ) $meta_key = 'pbe_platform_source';

        $statuses = array(
            'all'       => '',
            'publish'   => 'publish',
            'draft'     => 'draft',
            'pending'   => 'pending',
            'trash'     => 'trash'
        );

        foreach ( $statuses as $id => $status ) {
            $args = array(
                'post_type'  => $post_type,
                'meta_query' => array(
                    array(
                        'key'     => $meta_key,
                        'value'   => $active_platform,
                        'compare' => '='
                    )
                )
            );

            if ( $status ) {
                $args['post_status'] = $status;
            } else {
                $args['post_status'] = array( 'publish', 'draft', 'pending', 'future', 'private' );
            }

            $query = new WP_Query( $args );
            $count = $query->found_posts;

            if ( isset( $views[$id] ) ) {
                $views[$id] = preg_replace( '/\(.+\)/U', '(' . $count . ')', $views[$id] );
            }
        }

        return $views;
    }

    /**
     * Filter terms (Amenities/Tags) by the active platform
     */
    public function filter_terms_by_active_platform( $terms, $taxonomies, $args, $term_query ) {
        $active_taxonomies = array( 'amenity', 'property_tag' );
        $intersect = array_intersect( $taxonomies, $active_taxonomies );
        if ( empty( $intersect ) || empty( $terms ) ) return $terms;

        $active_platform = get_option( 'pbe_active_platform', 'guesty' );
        $hide_empty_admin_terms = is_admin() && $this->is_taxonomy_list_screen();
        
        static $platform_term_counts = array();
        
        // 1. Run the bulk query only once per page load per platform to completely eliminate N+1 DB Queries
        if ( ! isset( $platform_term_counts[ $active_platform ] ) ) {
            global $wpdb;
            $results = $wpdb->get_results( $wpdb->prepare( "
                SELECT tt.term_id, COUNT(DISTINCT p.ID) as property_count
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.taxonomy IN ('amenity', 'property_tag')
                AND pm.meta_key = 'platform_source'
                AND pm.meta_value = %s
                AND p.post_status = 'publish'
                AND p.post_type = 'property'
                GROUP BY tt.term_id
            ", $active_platform ) );
            
            $counts = array();
            if ( $results ) {
                foreach ( $results as $row ) {
                    $counts[ $row->term_id ] = (int) $row->property_count;
                }
            }
            $platform_term_counts[ $active_platform ] = $counts;
        }

        $base_counts = $platform_term_counts[ $active_platform ];
        $filtered_terms = array();
        
        foreach ( $terms as $term ) {
            if ( ! is_object( $term ) ) {
                $filtered_terms[] = $term;
                continue;
            }

            // 2. Add up counts instantaneously in PHP memory (including children terms)
            $term_ids = array( (int) $term->term_id );
            $children = get_term_children( $term->term_id, $term->taxonomy );
            if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
                $term_ids = array_merge( $term_ids, array_map( 'intval', $children ) );
            }
            
            $total_count = 0;
            foreach ( array_unique( array_filter( $term_ids ) ) as $id ) {
                if ( isset( $base_counts[ $id ] ) ) {
                    $total_count += $base_counts[ $id ];
                }
            }

            $term->count = $total_count;

            if ( $hide_empty_admin_terms && $term->count < 1 ) {
                continue;
            }

            $filtered_terms[] = $term;
        }

        return $hide_empty_admin_terms ? array_values( $filtered_terms ) : $terms;
    }

    /**
     * Filter the underlying SQL query for terms to fix pagination counts
     */
    public function filter_terms_query_by_platform( $clauses, $taxonomies, $args ) {
        $active_taxonomies = array( 'amenity', 'property_tag' );
        $intersect = array_intersect( (array)$taxonomies, $active_taxonomies );
        if ( empty( $intersect ) ) return $clauses;

        global $wpdb;
        $active_platform = get_option( 'pbe_active_platform', 'guesty' );

        $clauses['join'] .= " INNER JOIN {$wpdb->termmeta} AS pbe_tm ON t.term_id = pbe_tm.term_id";
        $clauses['where'] .= $wpdb->prepare( 
            " AND pbe_tm.meta_key = 'pbe_platform_source' AND pbe_tm.meta_value LIKE %s", 
            '%' . $wpdb->esc_like( $active_platform ) . '%' 
        );

        return $clauses;
    }

    /**
     * Helper to count properties assigned to a term for a specific platform
     */
    private function get_active_platform_term_count( $term_id, $taxonomy, $platform ) {
        global $wpdb;

        $term_ids = array( (int) $term_id );
        $children = get_term_children( $term_id, $taxonomy );
        if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
            $term_ids = array_merge( $term_ids, array_map( 'intval', $children ) );
        }

        $term_ids = array_unique( array_filter( $term_ids ) );
        $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

        $count = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.term_id IN ($placeholders)
            AND tt.taxonomy = %s
            AND pm.meta_key = 'platform_source'
            AND pm.meta_value = %s
            AND p.post_status = 'publish'
            AND p.post_type = 'property'
        ", array_merge( $term_ids, array( $taxonomy, $platform ) ) ) );

        return (int) $count;
    }

    /**
     * Checks whether the current admin request is the taxonomy list table.
     */
    private function is_taxonomy_list_screen() {
        global $pagenow;

        if ( $pagenow !== 'edit-tags.php' ) {
            return false;
        }

        $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( $_GET['taxonomy'] ) : '';
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';

        return in_array( $taxonomy, array( 'amenity', 'property_tag' ), true ) && $post_type === 'property';
    }

    /**
     * Hide parent amenity group counts in the admin list to avoid confusing group totals.
     */
    public function hide_parent_amenity_counts() {
        if ( ! $this->is_taxonomy_list_screen() || ( isset( $_GET['taxonomy'] ) && sanitize_key( $_GET['taxonomy'] ) !== 'amenity' ) ) {
            return;
        }

        $parents = get_terms( array(
            'taxonomy'   => 'amenity',
            'hide_empty' => false,
            'parent'     => 0,
            'fields'     => 'ids',
        ) );

        if ( empty( $parents ) || is_wp_error( $parents ) ) {
            return;
        }

        $selectors = array();
        foreach ( $parents as $parent_id ) {
            $children = get_term_children( $parent_id, 'amenity' );
            if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
                $selectors[] = '#tag-' . intval( $parent_id ) . ' .column-posts';
            }
        }

        if ( empty( $selectors ) ) {
            return;
        }
        ?>
        <style>
            <?php echo esc_html( implode( ',', $selectors ) ); ?> {
                color: transparent;
            }
            <?php echo esc_html( implode( ' a,', $selectors ) ); ?> a {
                visibility: hidden;
            }
        </style>
        <?php
    }
}
