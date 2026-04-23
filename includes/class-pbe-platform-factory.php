<?php
/**
 * Platform Factory
 * Manages the dynamic instantiation of platform adapters and enforces credential validation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Platform_Factory {

    /**
     * Gets an instance of a platform adapter if all required credentials are present.
     *
     * @param string $platform_id The ID of the platform (e.g., 'guesty', 'hostaway').
     * @return PBE_Platform_Interface|WP_Error Returns the adapter or a WP_Error if credentials missing.
     */
    public static function get_adapter( $platform_id ) {
        
        switch ( $platform_id ) {
            case 'guesty':
                $client_id     = get_option('pbe_guesty_client_id');
                $client_secret = get_option('pbe_guesty_client_secret');
                $account_id    = get_option('pbe_guesty_account_id'); // If required
                $api_endpoint  = get_option('pbe_guesty_api_endpoint');

                if ( empty( $client_id ) || empty( $client_secret ) ) {
                    return new WP_Error( 'missing_credentials', 'Guesty sync failed: Client ID and Client Secret are required in Platform Settings.' );
                }

                return new PBE_Guesty_Adapter( $client_id, $client_secret, $account_id, $api_endpoint );
                
            case 'hostaway':
                $api_key      = get_option('pbe_hostaway_api_key');
                $account_id   = get_option('pbe_hostaway_account_id');
                $manual_token = get_option('pbe_hostaway_manual_token');
                
                if ( empty( $api_key ) && empty( $manual_token ) ) {
                    return new WP_Error( 'missing_credentials', 'Hostaway sync failed: API Key or Manual Access Token is required in Platform Settings.' );
                }
                
                return new PBE_Hostaway_Adapter( $api_key, $account_id, $manual_token );

            case 'ownerrez':
                $api_key    = get_option('pbe_ownerrez_api_key');
                $api_secret = get_option('pbe_ownerrez_api_secret');
                
                if ( empty( $api_key ) || empty( $api_secret ) ) {
                    return new WP_Error( 'missing_credentials', 'OwnerRez sync failed: API Key and API Secret are required in Platform Settings.' );
                }
                
                return new WP_Error( 'not_implemented', 'OwnerRez adapter is not fully implemented yet.' );

            case 'hostfully':
                $api_key = get_option('pbe_hostfully_api_key');
                
                if ( empty( $api_key ) ) {
                    return new WP_Error( 'missing_credentials', 'Hostfully sync failed: API Key is required in Platform Settings.' );
                }
                
                return new WP_Error( 'not_implemented', 'Hostfully adapter is not fully implemented yet.' );

            default:
                return new WP_Error( 'invalid_platform', 'Invalid or unsupported platform selected.' );
        }
    }
}
