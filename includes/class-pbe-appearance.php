<?php
/**
 * PBE Appearance Settings
 *
 * Registers the "Appearance" admin sub-page under Property Booking.
 * Saves design tokens and outputs them as CSS custom properties on the front end.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Appearance {

    /** Defaults for each option */
    private static array $defaults = array(
        'pbe_color_primary'       => '#1e293b',
        'pbe_color_primary_hover' => '#415471',
        'pbe_color_text'          => '#6487BB',
        'pbe_color_muted'         => '#196EE6',
        'pbe_color_bg'            => '#ffffff',
        'pbe_color_card_bg'       => '#ffffff',
        'pbe_color_border'        => '#e5e7eb',
        'pbe_color_button'        => '#196EE6',
        'pbe_color_button_hover'  => '#1e293b',
        'pbe_color_icon'          => '#196EE6',
        'pbe_color_marker'        => '#196EE6',
        'pbe_color_cluster'       => '#196EE6',
        'pbe_color_accent'        => '#196EE6',
        'pbe_color_accent_muted'  => '#6366F133',
        'pbe_border_radius'       => '20px',
        'pbe_button_border_radius' => '100px',
        'pbe_font_source'         => 'plugin',
        'pbe_font_family'         => 'Inter, sans-serif',
        'pbe_listing_columns'     => '2',
        'pbe_posts_per_page'      => '12',
        'pbe_card_border_radius'  => '12px',
        'pbe_show_map'            => '1',
        'pbe_listing_container_width' => '1200px',
        'pbe_single_property_width'   => '1200px',
        'pbe_single_sticky_nav'       => '0',
    );

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'add_sub_page' ) );
        add_action( 'admin_init',            array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_head',               array( $this, 'output_css_variables' ), 20 );
    }

    // ── Sub-page ─────────────────────────────────────────────
    public function add_sub_page() {
        add_submenu_page(
            'pbe-platform-settings',
            'Property Appearance',
            'Appearance',
            'manage_options',
            'pbe-appearance',
            array( $this, 'render_page' )
        );
    }

    // ── Settings registration ─────────────────────────────────
    public function register_settings() {
        foreach ( array_keys( self::$defaults ) as $key ) {
            register_setting( 'pbe_appearance_group', $key, array(
                'sanitize_callback' => array( $this, 'sanitize_option' ),
            ) );
        }
    }

    public function sanitize_option( $value ) {
        return sanitize_text_field( $value );
    }

    // ── Admin assets ─────────────────────────────────────────
    public function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'property-booking_page_pbe-appearance' ) {
            return;
        }
        wp_enqueue_style( 'pbe-admin-css', PBE_PLUGIN_URL . 'assets/css/pbe-admin.css', array(), '2.0.0' );
        // Inline JS for live color preview
        wp_add_inline_script( 'jquery', $this->live_preview_js(), 'after' );
    }

    private function live_preview_js(): string {
        return <<<'JS'
        jQuery(function($){
            // Color/Text input changes
            $('[data-css-var]').on('input change', function(){
                var v = this.type === 'color' ? $(this).val() : $(this).val();
                document.documentElement.style.setProperty($(this).data('css-var'), v);
                // Sync paired text ↔ color inputs
                var pair = $(this).data('pair');
                if(pair) $(pair).val(v);
            });

            // Font source toggle
            $('#pbe_font_source').on('change', function(){
                var source = $(this).val();
                if (source === 'theme') {
                    $('#pbe_font_family_wrapper').css('opacity', '0.6');
                    document.documentElement.style.setProperty('--pbe-font', 'inherit');
                } else {
                    $('#pbe_font_family_wrapper').css('opacity', '1');
                    document.documentElement.style.setProperty('--pbe-font', $('#pbe_font_family').val());
                }
            });
        });
JS;
    }

    // ── CSS variable output ───────────────────────────────────
    public function output_css_variables() {
        $opts = $this->get_all();
        $font = ( $opts['pbe_font_source'] === 'plugin' ) ? $opts['pbe_font_family'] : 'inherit';
        
        echo "<style id=\"pbe-css-vars\">\n:root {\n";
        echo "  --pbe-primary: "       . esc_attr( $opts['pbe_color_primary'] )       . ";\n";
        echo "  --pbe-primary-hover: " . esc_attr( $opts['pbe_color_primary_hover'] ) . ";\n";
        echo "  --pbe-text: "          . esc_attr( $opts['pbe_color_text'] )           . ";\n";
        echo "  --pbe-muted: "         . esc_attr( $opts['pbe_color_muted'] )          . ";\n";
        echo "  --pbe-bg: "            . esc_attr( $opts['pbe_color_bg'] )             . ";\n";
        echo "  --pbe-card-bg: "       . esc_attr( $opts['pbe_color_card_bg'] )        . ";\n";
        echo "  --pbe-border: "        . esc_attr( $opts['pbe_color_border'] )         . ";\n";
        echo "  --pbe-button: "        . esc_attr( $opts['pbe_color_button'] )         . ";\n";
        echo "  --pbe-button-hover: "  . esc_attr( $opts['pbe_color_button_hover'] )   . ";\n";
        echo "  --pbe-icon-color: "    . esc_attr( $opts['pbe_color_icon'] )           . ";\n";
        echo "  --pbe-marker-color: "  . esc_attr( $opts['pbe_color_marker'] )         . ";\n";
        echo "  --pbe-cluster-color: " . esc_attr( $opts['pbe_color_cluster'] )        . ";\n";
        echo "  --pbe-radius: "        . esc_attr( $opts['pbe_border_radius'] )        . ";\n";
        echo "  --pbe-btn-radius: "    . esc_attr( $opts['pbe_button_border_radius'] ) . ";\n";
        echo "  --pbe-card-radius: "   . esc_attr( $opts['pbe_card_border_radius'] )   . ";\n";
        echo "  --pbe-font: "          . esc_attr( $font )                            . ";\n";
        echo "  --pbe-columns: "       . esc_attr( $opts['pbe_listing_columns'] )      . ";\n";
        echo "  --pbe-accent: "        . esc_attr( $opts['pbe_color_accent'] )         . ";\n";
        echo "  --pbe-accent-muted: "  . esc_attr( $opts['pbe_color_accent_muted'] )   . ";\n";
        echo "  --pbe-container-width: " . esc_attr( $opts['pbe_listing_container_width'] ) . ";\n";
        echo "}\n</style>\n";
    }

    // ── Helper ───────────────────────────────────────────────
    public static function get_all(): array {
        $out = array();
        foreach ( self::$defaults as $key => $default ) {
            $out[ $key ] = get_option( $key, $default );
        }
        return $out;
    }

    public static function get( string $key ): string {
        return get_option( $key, self::$defaults[ $key ] ?? '' );
    }

    // ── Render page ──────────────────────────────────────────
    public function render_page() {
        $opts = $this->get_all();
        ?>
        <div class="wrap pbe-appearance-wrap">
            <h1>Property Appearance Settings</h1>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><strong>Appearance settings saved!</strong> CSS variables updated on all property pages.</p></div>
            <?php endif; ?>

            <!-- Live preview bar -->
            <div class="pbe-appearance-preview">
                <strong>Live Preview:</strong>
                <button class="pbe-preview-btn"
                    style="background:<?php echo esc_attr($opts['pbe_color_primary']); ?>;color:#fff;"
                    id="pbe-preview-btn">Reserve Now</button>
                <span style="color:<?php echo esc_attr($opts['pbe_color_primary']); ?>;" id="pbe-preview-link">$&#160;250 / night</span>
                <span style="color:<?php echo esc_attr($opts['pbe_color_text']); ?>;" id="pbe-preview-text">Property Title</span>
                <span style="color:<?php echo esc_attr($opts['pbe_color_muted']); ?>;" id="pbe-preview-muted">Miami, FL · 4 guests</span>
                <span style="background:<?php echo esc_attr($opts['pbe_color_card_bg']); ?>; border:1px solid <?php echo esc_attr($opts['pbe_color_border']); ?>; border-radius:<?php echo esc_attr($opts['pbe_border_radius']); ?>; padding:6px 12px; font-size:12px;" id="pbe-preview-card">Card background</span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'pbe_appearance_group' ); ?>

                <!-- ── COLOURS ── -->
                <div class="pbe-appearance-section">
                    <div class="pbe-appearance-section-header">🎨 Colours</div>
                    <div class="pbe-appearance-section-body">
                        <?php $this->color_field( 'pbe_color_primary', 'Primary Accent Color', $opts['pbe_color_primary'], '--pbe-primary', 'h2, h3, .pbe-booking-price' ); ?>
                        <?php $this->color_field( 'pbe_color_primary_hover', 'Hover Color', $opts['pbe_color_primary_hover'], '--pbe-primary-hover' ); ?>
                        <?php $this->color_field( 'pbe_color_text', 'Body Text Color', $opts['pbe_color_text'], '--pbe-text', '#pbe-preview-text', 'Main text on cards and headings.' ); ?>
                        <?php $this->color_field( 'pbe_color_muted', 'Muted / Secondary Text', $opts['pbe_color_muted'], '--pbe-muted', '#pbe-preview-muted', 'Secondary info like price labels, meta.' ); ?>
                        <?php $this->color_field( 'pbe_color_bg', 'Page Background', $opts['pbe_color_bg'], '--pbe-bg', '', 'Background of listing/single pages.' ); ?>
                        <?php $this->color_field( 'pbe_color_card_bg', 'Card Background', $opts['pbe_color_card_bg'], '--pbe-card-bg', '#pbe-preview-card' ); ?>
                        <?php $this->color_field( 'pbe_color_border', 'Card Border Color', $opts['pbe_color_border'], '--pbe-border', '#pbe-preview-card' ); ?>
                        <?php $this->color_field( 'pbe_color_button', 'Main Action Button Color', $opts['pbe_color_button'], '--pbe-button', '#pbe-preview-btn' ); ?>
                        <?php $this->color_field( 'pbe_color_button_hover', 'Button Hover Color', $opts['pbe_color_button_hover'], '--pbe-button-hover' ); ?>
                        <?php $this->color_field( 'pbe_color_icon', 'Property Card Icon Color', $opts['pbe_color_icon'], '--pbe-icon-color' ); ?>
                        <?php $this->color_field( 'pbe_color_marker', 'Map Marker Color', $opts['pbe_color_marker'], '--pbe-marker-color' ); ?>
                        <?php $this->color_field( 'pbe_color_cluster', 'Map Cluster Color', $opts['pbe_color_cluster'], '--pbe-cluster-color' ); ?>

                        <!-- Sub-section for Booking Calendar -->
                        <div style="grid-column: 1 / -1; margin-top: 25px; margin-bottom: 5px; font-weight: 700; color: #1e293b; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                            Booking Calendar Colors
                        </div>
                        <?php $this->color_field( 'pbe_color_accent', 'Selection Accent Color', $opts['pbe_color_accent'], '--pbe-accent' ); ?>
                        <?php $this->color_field( 'pbe_color_accent_muted', 'Subtle Border / Today Color', $opts['pbe_color_accent_muted'], '--pbe-accent-muted' ); ?>
                    </div>
                </div>

                <!-- ── TYPOGRAPHY ── -->
                <div class="pbe-appearance-section">
                    <div class="pbe-appearance-section-header">✏️ Typography</div>
                    <div class="pbe-appearance-section-body">



                        <div class="pbe-appearance-field">
                            <label for="pbe_font_source">Font Source</label>
                            <select name="pbe_font_source" id="pbe_font_source">
                                <option value="theme" <?php selected( $opts['pbe_font_source'], 'theme' ); ?>>Use Theme Font (Inherit)</option>
                                <option value="plugin" <?php selected( $opts['pbe_font_source'], 'plugin' ); ?>>Use Plugin Font (Selected below)</option>
                            </select>
                            <p class="pbe-field-desc">Choose whether the plugin components should use your theme's font or the one selected below.</p>
                        </div>

                        <div class="pbe-appearance-field" id="pbe_font_family_wrapper" <?php echo ($opts['pbe_font_source'] === 'theme') ? 'style="opacity:0.6;"' : ''; ?>>
                            <label for="pbe_font_family">Plugin Font Family</label>
                            <select name="pbe_font_family" id="pbe_font_family" data-css-var="--pbe-font">
                                <?php
                                $fonts = array(
                                    'Inter, sans-serif'       => 'Inter',
                                    'Roboto, sans-serif'      => 'Roboto',
                                    'Outfit, sans-serif'      => 'Outfit',
                                    'Poppins, sans-serif'     => 'Poppins',
                                    'Lato, sans-serif'        => 'Lato',
                                    'Open Sans, sans-serif'   => 'Open Sans',
                                    'Montserrat, sans-serif'  => 'Montserrat',
                                    'Georgia, serif'          => 'Georgia (serif)',
                                    'sans-serif'              => 'System Default',
                                );
                                foreach ( $fonts as $val => $label ) {
                                    printf( '<option value="%s" %s style="font-family:%s">%s</option>',
                                        esc_attr( $val ), selected( $opts['pbe_font_family'], $val, false ), esc_attr( $val ), esc_html( $label ) );
                                }
                                ?>
                            </select>
                            <p class="pbe-field-desc">Font used in templates and shortcodes if "Use Plugin Font" is selected.</p>
                        </div>
                    </div>
                </div>

                <!-- ── LAYOUT ── -->
                <div class="pbe-appearance-section">
                    <div class="pbe-appearance-section-header">📐 Layout & UI</div>
                    <div class="pbe-appearance-section-body">

                        <div class="pbe-appearance-field">
                            <label for="pbe_border_radius">Search Form Border Radius</label>
                            <input type="text" name="pbe_border_radius" id="pbe_border_radius"
                                   value="<?php echo esc_attr( $opts['pbe_border_radius'] ); ?>"
                                   data-css-var="--pbe-radius" placeholder="e.g. 20px">
                            <p class="pbe-field-desc">e.g. <code>20px</code>, <code>100px</code>, <code>0</code></p>
                        </div>

                        <div class="pbe-appearance-field">
                            <label for="pbe_button_border_radius">Button Border Radius</label>
                            <input type="text" name="pbe_button_border_radius" id="pbe_button_border_radius"
                                   value="<?php echo esc_attr( $opts['pbe_button_border_radius'] ?? '100px' ); ?>"
                                   data-css-var="--pbe-btn-radius" placeholder="e.g. 100px">
                            <p class="pbe-field-desc">e.g. <code>8px</code>, <code>100px</code>, <code>0</code></p>
                        </div>

                        <div class="pbe-appearance-field">
                            <label for="pbe_card_border_radius">Property Card Border Radius</label>
                            <input type="text" name="pbe_card_border_radius" id="pbe_card_border_radius"
                                   value="<?php echo esc_attr( $opts['pbe_card_border_radius'] ?? '12px' ); ?>"
                                   data-css-var="--pbe-card-radius" placeholder="e.g. 12px">
                            <p class="pbe-field-desc">e.g. <code>12px</code>, <code>24px</code>, <code>0</code></p>
                        </div>

                        <div class="pbe-appearance-field">
                            <label for="pbe_listing_columns">Listing Grid Columns</label>
                            <select name="pbe_listing_columns" id="pbe_listing_columns">
                                <option value="1" <?php selected( $opts['pbe_listing_columns'], '1' ); ?>>1 Column (List)</option>
                                <option value="2" <?php selected( $opts['pbe_listing_columns'], '2' ); ?>>2 Columns (Grid)</option>
                                <option value="3" <?php selected( $opts['pbe_listing_columns'], '3' ); ?>>3 Columns (Grid)</option>
                            </select>
                            <p class="pbe-field-desc">Number of columns in the property listing grid.</p>
                        </div>

                        <div class="pbe-appearance-field">
                            <label for="pbe_posts_per_page">Properties Per Page</label>
                            <input type="number" name="pbe_posts_per_page" id="pbe_posts_per_page"
                                   value="<?php echo esc_attr( $opts['pbe_posts_per_page'] ?? '12' ); ?>"
                                   min="1" max="100">
                            <p class="pbe-field-desc">Number of properties to show on the listing page before pagination.</p>
                        </div>

                        <div class="pbe-appearance-field">
                            <label for="pbe_show_map">Show Map on Listing Page</label>
                            <select name="pbe_show_map" id="pbe_show_map">
                                <option value="1" <?php selected( $opts['pbe_show_map'], '1' ); ?>>Yes — show map panel</option>
                                <option value="0" <?php selected( $opts['pbe_show_map'], '0' ); ?>>No — hide map panel</option>
                            </select>
                            <p class="pbe-field-desc">Show the interactive map alongside the property grid.</p>
                        </div>
                        
                        <div class="pbe-appearance-field">
                            <label for="pbe_listing_container_width">Listing Container Max Width</label>
                            <input type="text" name="pbe_listing_container_width" id="pbe_listing_container_width"
                                   value="<?php echo esc_attr( $opts['pbe_listing_container_width'] ?? '1200px' ); ?>"
                                   data-css-var="--pbe-container-width" placeholder="e.g. 1200px">
                            <p class="pbe-field-desc">Max width for the property listing content area.</p>
                        </div>

                        <div class="pbe-appearance-field">
                            <label for="pbe_single_property_width">Single Property Container Max Width</label>
                            <input type="text" name="pbe_single_property_width" id="pbe_single_property_width"
                                   value="<?php echo esc_attr( $opts['pbe_single_property_width'] ?? '1200px' ); ?>"
                                   data-css-var="--pbe-single-width" placeholder="e.g. 1200px">
                            <p class="pbe-field-desc">Max width for the gallery and content on single property pages.</p>
                        </div>
                        
                        <div class="pbe-appearance-field">
                            <label for="pbe_single_sticky_nav">Single Property: Sticky Navigation Menu</label>
                            <select name="pbe_single_sticky_nav" id="pbe_single_sticky_nav">
                                <option value="1" <?php selected( $opts['pbe_single_sticky_nav'], '1' ); ?>>Enabled — show floating menu</option>
                                <option value="0" <?php selected( $opts['pbe_single_sticky_nav'], '0' ); ?>>Disabled — hide menu</option>
                            </select>
                            <p class="pbe-field-desc">Adds a horizontal "jump-to" menu below the photos on the single property page that sticks to the top while scrolling.</p>
                        </div>

                    </div>
                </div>

                <?php submit_button( 'Save Appearance Settings', 'primary large' ); ?>
            </form>
        </div>
        <?php
    }

    /** Helper: render a color field with paired text input */
    private function color_field( string $key, string $label, string $value, string $css_var, string $preview_sel = '', string $desc = '' ) {
        ?>
        <div class="pbe-appearance-field">
            <label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
            <div class="pbe-color-input-wrap">
                <input type="color"
                       id="<?php echo esc_attr( $key . '_picker' ); ?>"
                       value="<?php echo esc_attr( $value ); ?>"
                       data-css-var="<?php echo esc_attr( $css_var ); ?>"
                       data-pair="<?php echo esc_attr( '#' . $key ); ?>"
                       aria-label="<?php echo esc_attr( $label ); ?>">
                <input type="text"
                       id="<?php echo esc_attr( $key ); ?>"
                       name="<?php echo esc_attr( $key ); ?>"
                       value="<?php echo esc_attr( $value ); ?>"
                       data-css-var="<?php echo esc_attr( $css_var ); ?>"
                       data-pair="<?php echo esc_attr( '#' . $key . '_picker' ); ?>"
                       maxlength="30"
                       placeholder="#e61e4d">
            </div>
            <?php if ( $desc ) : ?>
                <p class="pbe-field-desc"><?php echo esc_html( $desc ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
