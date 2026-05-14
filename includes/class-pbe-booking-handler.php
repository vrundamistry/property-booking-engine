<?php
/**
 * Booking Handler
 * Handles AJAX requests for quotes and bookings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Booking_Handler {

    public function init() {
        add_action( 'wp_ajax_pbe_get_stay_quote', array( $this, 'ajax_get_stay_quote' ) );
        add_action( 'wp_ajax_nopriv_pbe_get_stay_quote', array( $this, 'ajax_get_stay_quote' ) );

        add_action( 'wp_ajax_pbe_get_property_config', array( $this, 'ajax_get_property_config' ) );
        add_action( 'wp_ajax_nopriv_pbe_get_property_config', array( $this, 'ajax_get_property_config' ) );

        add_action( 'wp_ajax_pbe_get_property_availability', array( $this, 'ajax_get_property_availability' ) );
        add_action( 'wp_ajax_nopriv_pbe_get_property_availability', array( $this, 'ajax_get_property_availability' ) );

        add_action( 'wp_ajax_pbe_submit_native_booking', array( $this, 'ajax_submit_native_booking' ) );
        add_action( 'wp_ajax_nopriv_pbe_submit_native_booking', array( $this, 'ajax_submit_native_booking' ) );

        add_action( 'wp_ajax_pbe_create_hostfully_lead', array( $this, 'ajax_create_hostfully_lead' ) );
        add_action( 'wp_ajax_nopriv_pbe_create_hostfully_lead', array( $this, 'ajax_create_hostfully_lead' ) );
    }

    /**
     * AJAX handler to fetch property initialization config (minimum nights, etc.)
     */
    public function ajax_get_property_config() {
        $property_id = isset( $_GET['property_id'] ) ? intval( $_GET['property_id'] ) : 0;
        if ( ! $property_id ) {
            wp_send_json_error( array( 'message' => 'Missing property ID.' ) );
        }

        $min_nights = get_post_meta( $property_id, 'min_nights', true );
        if ( ! $min_nights ) {
            $min_nights = 1; // Default
        }

        $max_guests = get_post_meta( $property_id, 'max_guests', true );

        wp_send_json_success( array(
            'property_id' => $property_id,
            'min_nights'  => intval( $min_nights ),
            'max_guests'  => intval( $max_guests )
        ) );
    }

    /**
     * AJAX handler to fetch a quote for a property
     */
    public function ajax_get_stay_quote() {
        $property_id = isset( $_POST['property_id'] ) ? intval( $_POST['property_id'] ) : 0;
        $checkin     = isset( $_POST['checkin'] ) ? sanitize_text_field( $_POST['checkin'] ) : '';
        $checkout    = isset( $_POST['checkout'] ) ? sanitize_text_field( $_POST['checkout'] ) : '';
        $guests      = isset( $_POST['guests'] ) ? intval( $_POST['guests'] ) : 1;

        if ( ! $property_id || ! $checkin || ! $checkout ) {
            wp_send_json_error( array( 'message' => 'Missing required parameters.' ) );
        }

        // Get Platform Details from Metadata
        $platform_source = get_post_meta( $property_id, 'platform_source', true );
        $platform_id     = get_post_meta( $property_id, 'platform_property_id', true );

        if ( ! $platform_source || ! $platform_id ) {
            wp_send_json_error( array( 'message' => 'Property is not linked to an external platform.' ) );
        }

        // Get Platform Adapter
        $adapter = PBE_Platform_Factory::get_adapter( $platform_source );
        if ( is_wp_error( $adapter ) ) {
            wp_send_json_error( array( 'message' => $adapter->get_error_message() ) );
        }

        // Fetch Quote
        $quote = $adapter->fetch_quote( $platform_id, $checkin, $checkout, $guests );

        if ( is_wp_error( $quote ) ) {
            wp_send_json_error( array( 'message' => $quote->get_error_message() ) );
        }

        wp_send_json_success( $quote );
    }

    /**
     * AJAX handler to create a Hostfully Lead for booking redirect
     */
    public function ajax_create_hostfully_lead() {
        $property_id = isset( $_POST['property_id'] ) ? intval( $_POST['property_id'] ) : 0;
        $checkin     = isset( $_POST['checkin'] ) ? sanitize_text_field( $_POST['checkin'] ) : '';
        $checkout    = isset( $_POST['checkout'] ) ? sanitize_text_field( $_POST['checkout'] ) : '';
        $guests      = isset( $_POST['guests'] ) ? intval( $_POST['guests'] ) : 1;

        if ( ! $property_id || ! $checkin || ! $checkout ) {
            wp_send_json_error( array( 'message' => 'Missing required parameters.' ) );
        }

        // Localhost bypass to avoid spamming live account with test leads
        if ( isset($_SERVER['HTTP_HOST']) && ( strpos( $_SERVER['HTTP_HOST'], 'localhost' ) !== false || strpos( $_SERVER['HTTP_HOST'], '127.0.0.1' ) !== false || strpos( $_SERVER['HTTP_HOST'], '.local' ) !== false ) ) {
            wp_send_json_success( array( 'is_local' => true ) );
        }

        $platform_source = get_post_meta( $property_id, 'platform_source', true );
        $platform_id     = get_post_meta( $property_id, 'platform_property_id', true );

        if ( $platform_source !== 'hostfully' ) {
            wp_send_json_error( array( 'message' => 'Invalid platform source.' ) );
        }

        $adapter = PBE_Platform_Factory::get_adapter( $platform_source );
        if ( is_wp_error( $adapter ) ) {
            wp_send_json_error( array( 'message' => $adapter->get_error_message() ) );
        }

        if ( ! method_exists( $adapter, 'create_lead' ) ) {
            wp_send_json_error( array( 'message' => 'Platform does not support lead creation.' ) );
        }

        $lead_id = $adapter->create_lead( $platform_id, $checkin, $checkout, $guests );

        if ( is_wp_error( $lead_id ) ) {
            wp_send_json_error( array( 'message' => $lead_id->get_error_message() ) );
        }

        wp_send_json_success( array( 'lead_id' => $lead_id ) );
    }

    /**
     * AJAX handler to fetch real-time availability for a property
     */
    public function ajax_get_property_availability() {
        $property_id = isset( $_GET['property_id'] ) ? intval( $_GET['property_id'] ) : 0;
        $from        = isset( $_GET['from'] ) ? sanitize_text_field( $_GET['from'] ) : ''; // YYYY-MM-DD
        $to          = isset( $_GET['to'] ) ? sanitize_text_field( $_GET['to'] ) : '';   // YYYY-MM-DD

        if ( ! $property_id || ! $from || ! $to ) {
            wp_send_json_error( array( 'message' => 'Missing required parameters.' ) );
        }

        // Get Platform Details from Metadata
        $platform_source = get_post_meta( $property_id, 'platform_source', true );
        $platform_id     = get_post_meta( $property_id, 'platform_property_id', true );

        if ( ! $platform_source || ! $platform_id ) {
            wp_send_json_error( array( 'message' => 'Property is not linked to an external platform.' ) );
        }

        // Get Platform Adapter
        $adapter = PBE_Platform_Factory::get_adapter( $platform_source );
        if ( is_wp_error( $adapter ) ) {
            wp_send_json_error( array( 'message' => $adapter->get_error_message() ) );
        }

        // Fetch Availability
        $calendar = $adapter->fetch_availability( $platform_id, $from, $to );

        if ( is_wp_error( $calendar ) ) {
            wp_send_json_error( array( 'message' => $calendar->get_error_message() ) );
        }

        wp_send_json_success( $calendar );
    }

    /**
     * AJAX handler to submit a native on-site booking
     */
    public function ajax_submit_native_booking() {
        $property_id = isset( $_POST['property_id'] ) ? intval( $_POST['property_id'] ) : 0;
        $checkin     = isset( $_POST['checkin'] ) ? sanitize_text_field( $_POST['checkin'] ) : '';
        $checkout    = isset( $_POST['checkout'] ) ? sanitize_text_field( $_POST['checkout'] ) : '';
        $guests      = isset( $_POST['guests'] ) ? intval( $_POST['guests'] ) : 1;
        $guest_data  = isset( $_POST['guest_data'] ) ? $_POST['guest_data'] : array();

        if ( ! $property_id || ! $checkin || ! $checkout || empty($guest_data) ) {
            wp_send_json_error( array( 'message' => 'Missing required parameters.' ) );
        }

        // 1. Get Platform Details
        $platform_source = get_post_meta( $property_id, 'platform_source', true );
        $platform_id     = get_post_meta( $property_id, 'platform_property_id', true );

        // 2. Test Mode & Security Check
        $test_mode       = get_option('pbe_test_mode') === '1';
        $test_listing_id = get_option('pbe_test_listing_id');

        if ( $test_mode ) {
            if ( empty($test_listing_id) ) {
                wp_send_json_error( array( 'message' => 'Test Mode is active, but no Test Listing ID is configured. All bookings are disabled for safety.' ) );
            }
            if ( $platform_id !== $test_listing_id ) {
                wp_send_json_error( array( 'message' => 'Test Mode is active. Reservations are only allowed on the designated Test Listing (ID: ' . esc_html($test_listing_id) . ').' ) );
            }
        }

        // 3. Real-Time Final Check (Bypass Cache)
        $adapter = PBE_Platform_Factory::get_adapter( $platform_source );
        if ( is_wp_error( $adapter ) ) {
            wp_send_json_error( array( 'message' => $adapter->get_error_message() ) );
        }
        
        $quote = $adapter->fetch_quote( $platform_id, $checkin, $checkout, $guests, true ); // force_refresh = true
        
        if ( is_wp_error($quote) ) {
            wp_send_json_error( array( 'message' => 'Final availability check failed: ' . $quote->get_error_message() ) );
        }

        $total_price = isset($quote['total']) ? $quote['total'] : 0;

        // 4. Create Local Reservation (WordPress)
        $res_title = sanitize_text_field($guest_data['first_name'] . ' ' . $guest_data['last_name']);
        $local_res_id = wp_insert_post( array(
            'post_type'   => 'pbe_reservation',
            'post_title'  => $res_title,
            'post_status' => 'publish',
            'meta_input'  => array(
                'pbe_property_id'   => $property_id,
                'pbe_checkin'       => $checkin,
                'pbe_checkout'      => $checkout,
                'pbe_guests'        => $guests,
                'pbe_guest_email'   => sanitize_email( $guest_data['email'] ),
                'pbe_guest_phone'   => sanitize_text_field( $guest_data['phone'] ),
                'pbe_total_price'   => $total_price,
                'pbe_status'        => 'pending',
                'pbe_platform'      => $platform_source,
                'pbe_is_test'       => $test_mode ? '1' : '0'
            )
        ) );

        // 5. Process Stripe Payment
        $payment_method_id = isset($_POST['payment_method_id']) ? sanitize_text_field($_POST['payment_method_id']) : '';
        $currency          = get_post_meta( $property_id, 'currency', true ) ?: 'USD';

        if ( empty($payment_method_id) ) {
            wp_send_json_error( array( 'message' => 'Payment method is required.' ) );
        }

        $stripe_result = $this->process_stripe_payment( 
            $total_price, 
            $currency, 
            $payment_method_id, 
            $guest_data['email'],
            "Reservation for " . get_the_title($property_id) . " ({$checkin} to {$checkout})"
        );

        if ( is_wp_error($stripe_result) ) {
            update_post_meta( $local_res_id, 'pbe_status', 'payment_failed' );
            update_post_meta( $local_res_id, 'pbe_error', $stripe_result->get_error_message() );
            wp_send_json_error( array( 'message' => 'Payment failed: ' . $stripe_result->get_error_message() ) );
        }

        // Store payment info
        update_post_meta( $local_res_id, 'pbe_stripe_intent_id', $stripe_result['id'] );
        update_post_meta( $local_res_id, 'pbe_stripe_receipt_url', $stripe_result['receipt_url'] );

        // 6. Send to Platform (Guesty)
        $response = $adapter->create_reservation( $platform_id, $checkin, $checkout, $guests, $guest_data, $quote );

        if ( is_wp_error( $response ) ) {
            // Guesty Failed! We must REFUND the payment as requested.
            $this->refund_stripe_payment( $stripe_result['id'], 'fraudulent' ); 

            // Update local record as failed
            update_post_meta( $local_res_id, 'pbe_status', 'failed' );
            update_post_meta( $local_res_id, 'pbe_error', 'Guesty Error: ' . $response->get_error_message() . '. Your payment has been automatically refunded.' );
            wp_send_json_error( array( 'message' => 'Booking failed in Guesty: ' . $response->get_error_message() . '. Your payment has been refunded.' ) );
        }

        // 7. Success! Update local status and store platform ID
        $platform_res_id = isset( $response['id'] ) ? $response['id'] : (isset($response['_id']) ? $response['_id'] : 'CONFIRMED');
        update_post_meta( $local_res_id, 'pbe_platform_res_id', $platform_res_id );
        update_post_meta( $local_res_id, 'pbe_status', 'confirmed' ); // Mark as confirmed since payment was processed.

        // 6. DB Sync & Quote Cache Purge to reflect the new booking immediately
        PBE_Availability_Sync::sync_property_availability( $platform_id, $adapter );
        $adapter->purge_quote_cache( $platform_id );
 
        // 7. Send Confirmation Email
        $this->send_booking_confirmation_email( $local_res_id );
 
        // 8. Get Payment Info for success message
        $receipt_url = get_post_meta( $local_res_id, 'pbe_stripe_receipt_url', true );

        wp_send_json_success( array(
            'message'        => 'Reservation request submitted successfully and payment captured.',
            'reservation_id' => $platform_res_id,
            'local_id'       => $local_res_id,
            'receipt_url'    => $receipt_url
        ) );
    }

    /**
     * Helper to process Stripe payment via raw cURL
     */
    private function process_stripe_payment( $amount, $currency, $payment_method_id, $guest_email, $description ) {
        $mode = get_option('pbe_stripe_mode', 'test');
        $secret_key = ($mode === 'live') ? get_option('pbe_stripe_live_sec') : get_option('pbe_stripe_test_sec');

        if ( empty($secret_key) ) {
            return new WP_Error('stripe_error', 'Stripe Secret Key is not configured.');
        }

        // Stripe expects amounts in cents
        $amount_cents = round( (float) $amount * 100 );

        $post_fields = array(
            'amount'                    => $amount_cents,
            'currency'                  => strtolower($currency),
            'payment_method'            => $payment_method_id,
            'confirm'                   => 'true',
            'receipt_email'             => $guest_email,
            'description'               => $description,
            'automatic_payment_methods[enabled]'         => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never'
        );

        $response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Stripe-Version' => '2023-10-16'
            ),
            'body' => $post_fields
        ) );

        if ( is_wp_error($response) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body($response), true );

        if ( isset($body['error']) ) {
            return new WP_Error('stripe_api_error', $body['error']['message']);
        }

        if ( $body['status'] === 'succeeded' ) {
            return array(
                'id'          => $body['id'],
                'receipt_url' => isset($body['charges']['data'][0]['receipt_url']) ? $body['charges']['data'][0]['receipt_url'] : ''
            );
        }

        return new WP_Error('stripe_status_error', 'Stripe Payment Status: ' . $body['status']);
    }

    /**
     * Helper to refund a Stripe payment
     */
    private function refund_stripe_payment( $payment_intent_id, $reason = 'requested_by_customer' ) {
        $mode = get_option('pbe_stripe_mode', 'test');
        $secret_key = ($mode === 'live') ? get_option('pbe_stripe_live_sec') : get_option('pbe_stripe_test_sec');

        if ( empty($secret_key) ) return false;

        wp_remote_post( 'https://api.stripe.com/v1/refunds', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded'
            ),
            'body' => array(
                'payment_intent' => $payment_intent_id,
                'reason'         => $reason
            )
        ) );
    }
 
    /**
     * Send an HTML confirmation email to the guest and admin
     */
    private function send_booking_confirmation_email( $res_post_id ) {
        $property_id = get_post_meta( $res_post_id, 'pbe_property_id', true );
        $checkin     = get_post_meta( $res_post_id, 'pbe_checkin', true );
        $checkout    = get_post_meta( $res_post_id, 'pbe_checkout', true );
        $guests      = get_post_meta( $res_post_id, 'pbe_guests', true );
        $email       = get_post_meta( $res_post_id, 'pbe_guest_email', true );
        $total       = get_post_meta( $res_post_id, 'pbe_total_price', true );
        $receipt_url = get_post_meta( $res_post_id, 'pbe_stripe_receipt_url', true );
        $res_id      = get_post_meta( $res_post_id, 'pbe_platform_res_id', true );
        $platform    = get_post_meta( $res_post_id, 'pbe_platform', true );
        $platform_name = !empty($platform) ? ucfirst($platform) : 'Platform';
 
        $res_title   = get_the_title( $res_post_id );
        $address     = get_post_meta( $property_id, 'full_address', true );
        $property_name = get_the_title( $property_id );
        $primary_color = PBE_Appearance::get( 'pbe_color_primary' ) ?: '#196EE6';

        // Calculate Nights
        $d1 = new DateTime($checkin);
        $d2 = new DateTime($checkout);
        $nights = $d1->diff($d2)->days;

        $subject = "Booking Confirmed: " . $property_name;
        
        $body = "
        <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee;'>
            <div style='background: {$primary_color}; padding: 30px; text-align: center; color: #ffffff;'>
                <h1 style='margin: 0; font-size: 24px;'>Reservation Confirmed!</h1>
                <p style='margin: 10px 0 0; opacity: 0.9;'>Request ID: {$res_id}</p>
            </div>
            <div style='padding: 30px;'>
                <p style='font-size: 16px; color: #333;'>Hello <strong>{$res_title}</strong>,</p>
                <p style='font-size: 16px; color: #333;'>Your reservation for <strong>{$property_name}</strong> has been successfully processed and confirmed.</p>
                
                <div style='background: #f8f9fa; border-radius: 12px; padding: 20px; margin: 25px 0;'>
                    <h3 style='margin-top: 0; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: #666;'>Property Information</h3>
                    <p style='margin: 5px 0; font-size: 16px; font-weight: bold;'>{$property_name}</p>
                    <p style='margin: 0; font-size: 14px; color: #666;'>{$address}</p>
                </div>

                <table style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                    <tr>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; color: #666;'>Dates</td>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; text-align: right; font-weight: bold;'>" . date('M j, Y', strtotime($checkin)) . " - " . date('M j, Y', strtotime($checkout)) . "</td>
                    </tr>
                    <tr>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; color: #666;'>Duration</td>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; text-align: right; font-weight: bold;'>{$nights} Nights</td>
                    </tr>
                    <tr>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; color: #666;'>Guests</td>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; text-align: right; font-weight: bold;'>{$guests} Guests</td>
                    </tr>
                    <tr>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; color: #666;'>Total Amount</td>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; text-align: right; font-weight: bold; color: #2DBC7C;'>$" . number_format($total, 2) . "</td>
                    </tr>
                </table>";
 
        if ( $receipt_url ) {
            $body .= "
                <div style='text-align: center; margin-top: 30px;'>
                    <a href='{$receipt_url}' style='background: #f8f9fa; color: {$primary_color}; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14px; border: 1px solid #eee;'>View Stripe Receipt</a>
                </div>";
        }
 
        $body .= "
                <p style='font-size: 14px; color: #999; margin-top: 40px;'>If you have any questions, please reply to this email or contact us through our website.</p>
            </div>
            <div style='background: #f9f9f9; padding: 20px; text-align: center; font-size: 12px; color: #777;'>
                &copy; " . date('Y') . " " . get_bloginfo('name') . ". All rights reserved.
            </div>
        </div>";
 
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // Send to guest
        wp_mail( $email, $subject, $body, $headers );
  
        // Create Admin-Specific Body
        $admin_body = "
        <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee;'>
            <div style='background: #333; padding: 30px; text-align: center; color: #ffffff;'>
                <h1 style='margin: 0; font-size: 24px;'>New Booking Received</h1>
                <p style='margin: 10px 0 0; opacity: 0.9;'>Property: {$property_name}</p>
            </div>
            <div style='padding: 30px;'>
                <h3 style='margin-top: 0; color: #196EE6;'>Reservation Details</h3>
                <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                    <tr><td style='padding: 8px 0; color: #666;'>Guest Name:</td><td style='font-weight: bold;'>{$res_title}</td></tr>
                    <tr><td style='padding: 8px 0; color: #666;'>Guest Email:</td><td style='font-weight: bold;'>{$email}</td></tr>
                    <tr><td style='padding: 8px 0; color: #666;'>Guest Phone:</td><td style='font-weight: bold;'>" . get_post_meta( $res_post_id, 'pbe_guest_phone', true ) . "</td></tr>
                    <tr><td style='padding: 8px 0; color: #666;'>Dates:</td><td style='font-weight: bold;'>{$checkin} to {$checkout} ({$nights} nights)</td></tr>
                    <tr><td style='padding: 8px 0; color: #666;'>Total Paid:</td><td style='font-weight: bold; color: #2DBC7C;'>$" . number_format($total, 2) . "</td></tr>
                    <tr><td style='padding: 8px 0; color: #666;'>{$platform_name} ID:</td><td style='font-family: monospace;'>{$res_id}</td></tr>
                </table>

                <div style='text-align: center; margin-top: 30px;'>
                    <a href='" . admin_url("post.php?post={$res_post_id}&action=edit") . "' style='background: #196EE6; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14px;'>View Reservation in WordPress</a>
                </div>
            </div>
        </div>";

        // Send a copy to admin
        $admin_email = get_option('admin_email');
        wp_mail( $admin_email, "[Admin Notice] New Booking: " . $property_name . " (" . $res_title . ")", $admin_body, $headers );
    }
}
