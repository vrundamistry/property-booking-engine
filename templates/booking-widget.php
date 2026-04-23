<?php
/**
 * Booking Widget Template Part
 * 
 * Used by shortcode [pbe_booking_widget] and single property pages.
 * 
 * @var $property_id int
 * @var $price float|int
 * @var $max_guests int
 * @var $checkin string
 * @var $checkout string
 * @var $guests int
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$price_formatted = $price ? '$' . number_format( (float) $price ) : 'N/A';
?>

<?php 
$platform_id     = get_post_meta( $property_id, 'platform_property_id', true );
$platform_source = get_post_meta( $property_id, 'platform_source', true );

if ( $platform_source === 'hostaway' ) {
    $booking_domain = get_option('pbe_hostaway_booking_domain');
} else {
    $booking_domain = get_option('pbe_guesty_booking_domain', 'guestybookings.com');
}
?>
<div class="pbe-booking-widget-container" 
     data-property-id="<?php echo esc_attr( $property_id ); ?>"
     data-platform-id="<?php echo esc_attr( $platform_id ); ?>"
     data-platform-source="<?php echo esc_attr( $platform_source ); ?>"
     data-booking-domain="<?php echo esc_attr( $booking_domain ); ?>">
    
    <div class="pbe-widget-price">
        <?php echo esc_html( $price_formatted ); ?> <span>/ night</span>
    </div>

    <div id="pbe-min-stay-notice" class="pbe-min-stay-notice" style="display: none;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span class="pbe-ms-text">Minimum stay: 2 nights</span>
    </div>

    <div class="pbe-boxed-container">
        <div class="pbe-boxed-row">
            <div class="pbe-boxed-field">
                <label class="pbe-boxed-label">CHECK-IN Date</label>
                <input type="text" id="pbe-sp-checkin" class="pbe-boxed-value" 
                       placeholder="Add date" value="<?php echo esc_attr( $checkin ?? '' ); ?>" readonly>
            </div>
            <div class="pbe-boxed-field">
                <label class="pbe-boxed-label">CHECK-OUT Date</label>
                <input type="text" id="pbe-sp-checkout" class="pbe-boxed-value" 
                       placeholder="Add date" value="<?php echo esc_attr( $checkout ?? '' ); ?>" readonly>
            </div>
        </div>
        <div class="pbe-boxed-row" style="grid-template-columns: 1fr;">
            <div class="pbe-boxed-field has-chevron" id="pbe-sp-guests-container" style="border-right: none;">
                <label class="pbe-boxed-label">GUESTS</label>
                <div class="pbe-custom-dropdown" id="pbe-sp-guests-dropdown">
                    <div class="pbe-cd-trigger">
                        <span class="pbe-cd-label pbe-boxed-value"><?php 
                            echo $guests ? sprintf('%d Guests', $guests) : '2 Guests'; 
                        ?></span>
                    </div>
                    <ul class="pbe-cd-options">
                        <?php for ( $g = 2; $g <= 12; $g++ ) : ?>
                            <li data-value="<?php echo $g; ?>" <?php echo ( $g == $guests ) ? 'class="active"' : ''; ?>>
                                <?php echo $g; ?> Guests
                            </li>
                        <?php endfor; ?>
                    </ul>
                    <input type="hidden" name="guests" id="pbe-sp-guests-val" value="<?php echo esc_attr( $guests ); ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="pbe-price-breakdown" id="pbe-price-breakdown-target" style="display: none;">
        <!-- Dynamically populated by pbe-frontend.js -->
    </div>

    <button class="pbe-reserve-btn" id="pbe-book-now" disabled>Book Now</button>
</div>
