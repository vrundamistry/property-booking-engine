<?php
/**
 * Native Booking Widget Template
 * Multi-step on-site booking flow.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$price      = get_post_meta( $property_id, 'price_per_night', true );
$max_guests = get_post_meta( $property_id, 'max_guests', true );
$platform_id = get_post_meta( $property_id, 'platform_property_id', true );

$price_formatted = $price ? '$' . number_format( (float) $price ) : 'N/A';
?>

<div class="pbe-native-booking-wrapper" 
     id="pbe-native-widget"
     style="opacity: 0; visibility: hidden; transition: opacity 0.4s ease-in-out;"
     data-property-id="<?php echo esc_attr( $property_id ); ?>"
     data-property-name="<?php echo esc_attr( get_the_title( $property_id ) ); ?>"
     data-platform-id="<?php echo esc_attr( $platform_id ); ?>">
    
    <!-- Step Indicator -->
    <div class="pbe-nb-steps">
        <div class="pbe-nb-step active" data-step="1"><span>1</span> Dates</div>
        <div class="pbe-nb-step" data-step="2"><span>2</span> Details</div>
        <div class="pbe-nb-step" data-step="3"><span>3</span> Confirm</div>
    </div>

    <!-- Step 1: Selection -->
    <div class="pbe-nb-content active" id="pbe-step-1">
        <div class="pbe-nb-price-header">
            <span class="pbe-nb-amount"><?php echo esc_html( $price_formatted ); ?></span>
            <span class="pbe-nb-label">/ night</span>
        </div>

        <div class="pbe-nb-inputs">
            <div class="pbe-nb-input-group pbe-nb-dates">
                <div class="pbe-nb-field">
                    <label>CHECK-IN</label>
                    <input type="text" id="pbe-nb-checkin" placeholder="Add date" readonly value="<?php echo esc_attr($checkin); ?>">
                </div>
                <div class="pbe-nb-field">
                    <label>CHECK-OUT</label>
                    <input type="text" id="pbe-nb-checkout" placeholder="Add date" readonly value="<?php echo esc_attr($checkout); ?>">
                </div>
            </div>
            
            <div class="pbe-nb-input-group pbe-nb-guests">
                <label>GUESTS</label>
                <div class="pbe-nb-guest-trigger">
                    <span id="pbe-nb-guest-label"><?php echo $guests; ?> Guests</span>
                    <input type="hidden" id="pbe-nb-guests-val" value="<?php echo $guests; ?>">
                </div>
                <div class="pbe-nb-guest-dropdown">
                    <?php for($i=2; $i<=12; $i++): ?>
                        <div class="pbe-nb-guest-option <?php echo ($i==$guests) ? 'active' : ''; ?>" data-value="<?php echo $i; ?>">
                            <?php echo $i; ?> Guests
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <div id="pbe-nb-price-breakdown" style="display:none;">
            <!-- AJAX loaded pricing -->
        </div>

        <button type="button" class="pbe-nb-next-btn" id="pbe-nb-goto-2" disabled>
            Next: Guest Details
        </button>
        
        <p class="pbe-nb-note">No charge yet</p>
    </div>

    <!-- Step 2: Guest Details -->
    <div class="pbe-nb-content" id="pbe-step-2">
        <h3>Guest Information</h3>
        <p class="pbe-nb-subtitle">Please provide your contact details to proceed.</p>
        
        <div class="pbe-nb-form">
            <div class="pbe-nb-field">
                <label for="pbe-nb-fname">First Name</label>
                <input type="text" id="pbe-nb-fname" placeholder="John" required>
            </div>
            <div class="pbe-nb-field">
                <label for="pbe-nb-lname">Last Name</label>
                <input type="text" id="pbe-nb-lname" placeholder="Doe" required>
            </div>
            <div class="pbe-nb-field">
                <label for="pbe-nb-email">Email Address</label>
                <input type="email" id="pbe-nb-email" placeholder="john@example.com" required>
            </div>
            <div class="pbe-nb-field">
                <label for="pbe-nb-phone">Phone Number</label>
                <input type="tel" id="pbe-nb-phone" placeholder="+1 (555) 000-0000" required>
            </div>
        </div>

        <div class="pbe-nb-actions">
            <button type="button" class="pbe-nb-back-link" id="pbe-nb-back-to-1">Back</button>
            <button type="button" class="pbe-nb-next-btn" id="pbe-nb-goto-3">Review Booking</button>
        </div>
    </div>

    <!-- Step 3: Payment & Review -->
    <div class="pbe-nb-content" id="pbe-step-3">
        <h3>Payment & Review</h3>
        
        <div class="pbe-nb-summary-box">
            <div class="pbe-nb-summary-row">
                <span>Dates:</span>
                <span id="pbe-nb-sum-dates">---</span>
            </div>
            <div class="pbe-nb-summary-row">
                <span>Guests:</span>
                <span id="pbe-nb-sum-guests">---</span>
            </div>
            <div class="pbe-nb-summary-row total">
                <span>Total Amount:</span>
                <span id="pbe-nb-sum-total">---</span>
            </div>
        </div>

        <div class="pbe-nb-payment-section">
            <label>Credit or Debit Card</label>
            <div id="pbe-stripe-card-element" class="pbe-nb-stripe-field">
                <!-- Stripe Element will be inserted here -->
            </div>
            <div id="pbe-stripe-errors" role="alert" class="pbe-nb-field-error"></div>
        </div>

        <div class="pbe-nb-secure-notice">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-1 15.41l-3.29-3.29 1.41-1.41L11 13.59l5.12-5.12 1.41 1.41L11 16.41z"/></svg>
            Secure, encrypted payment powered by Stripe.
        </div>

        <button type="button" class="pbe-nb-next-btn primary" id="pbe-nb-submit">
            Pay & Book Now
        </button>
        <button type="button" class="pbe-nb-back-link" id="pbe-nb-back-to-2">Back to details</button>
    </div>

    <!-- Step 4: Success Message -->
    <div class="pbe-nb-content" id="pbe-step-success">
        <div class="pbe-nb-success-icon">
            <svg viewBox="0 0 24 24" width="60" height="60" fill="currentColor"><circle cx="12" cy="12" r="10" fill="#E8F9F1"/><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z" fill="#2DBC7C"/></svg>
        </div>
        <h3>Request Submitted!</h3>
        <p>Your reservation request has been sent for review. A confirmation email has been sent to your inbox.</p>
        <div id="pbe-nb-success-details" class="pbe-nb-success-summary"></div>
        <div class="pbe-nb-res-id">Request ID: <span id="pbe-nb-success-id">---</span></div>
        <button type="button" class="pbe-nb-next-btn" onclick="window.location.reload()">Close</button>
    </div>

    <div id="pbe-nb-loader" style="display:none;">
        <div class="pbe-nb-spinner"></div>
        <p>Processing your reservation...</p>
    </div>

</div>
