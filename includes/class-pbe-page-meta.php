<?php
/**
 * PBE Page Meta
 *
 * Adds custom meta boxes for Pages, specifically for those using
 * the Property Listing page template.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Page_Meta {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post',       array( $this, 'save_meta_boxes' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function register_meta_boxes() {
        add_meta_box(
            'pbe_listing_settings',
            'Property Listing Settings (PBE)',
            array( $this, 'render_meta_box' ),
            'page',
            'normal',
            'high'
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
            return;
        }
        wp_enqueue_media();
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'pbe_page_meta_nonce', 'pbe_page_meta_nonce_field' );
        $hero_image = get_post_meta( $post->ID, '_pbe_listing_hero_image', true );
        ?>
        <div class="pbe-meta-field">
            <p><strong>Hero Background Image</strong></p>
            <div id="pbe-hero-preview" style="margin-bottom: 10px;">
                <?php if ( $hero_image ) : ?>
                    <img src="<?php echo esc_url( $hero_image ); ?>" style="max-width: 100%; height: auto; border-radius: 8px; border: 1px solid #ddd;">
                <?php endif; ?>
            </div>
            <input type="hidden" name="pbe_listing_hero_image" id="pbe_listing_hero_image" value="<?php echo esc_attr( $hero_image ); ?>">
            <button type="button" class="button" id="pbe-upload-hero-btn">Select Image</button>
            <button type="button" class="button pbe-remove-hero-btn" style="<?php echo $hero_image ? '' : 'display:none;'; ?>">Remove</button>
            <p class="description">Upload or select a custom background image for the Hero section of this listing page.</p>
        </div>

        <script>
        jQuery(document).ready(function($){
            var mediaUploader;
            $('#pbe-upload-hero-btn').click(function(e) {
                e.preventDefault();
                if (mediaUploader) {
                    mediaUploader.open();
                    return;
                }
                mediaUploader = wp.media({
                    title: 'Select Hero Image',
                    button: { text: 'Use this Image' },
                    multiple: false
                });
                mediaUploader.on('select', function() {
                    var attachment = mediaUploader.state().get('selection').first().toJSON();
                    $('#pbe_listing_hero_image').val(attachment.url);
                    $('#pbe-hero-preview').html('<img src="' + attachment.url + '" style="max-width: 100%; height: auto; border-radius: 8px; border: 1px solid #ddd;">');
                    $('.pbe-remove-hero-btn').show();
                });
                mediaUploader.open();
            });

            $('.pbe-remove-hero-btn').click(function(){
                $('#pbe_listing_hero_image').val('');
                $('#pbe-hero-preview').html('');
                $(this).hide();
            });

            // Optional: Only show this meta box if Property Listing template is selected
            function togglePbeMeta() {
                var isPbe = $('#page_template').val() === 'pbe-property-listing.php';
                $('#pbe_listing_settings').toggle(isPbe);
            }
            $('#page_template').change(togglePbeMeta);
            togglePbeMeta();
        });
        </script>
        <?php
    }

    public function save_meta_boxes( $post_id ) {
        if ( ! isset( $_POST['pbe_page_meta_nonce_field'] ) || ! wp_verify_nonce( $_POST['pbe_page_meta_nonce_field'], 'pbe_page_meta_nonce' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_page', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['pbe_listing_hero_image'] ) ) {
            update_post_meta( $post_id, '_pbe_listing_hero_image', esc_url_raw( $_POST['pbe_listing_hero_image'] ) );
        }
    }
}
