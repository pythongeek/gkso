<?php
/**
 * GKSO_Security class
 * 
 * HMAC signature verification for n8n callbacks, IP allowlisting,
 * per-user daily rate limits, and nonce validation.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_Security {
    
    // Header name for signature verification
    const SIGNATURE_HEADER = 'X-GKSO-Signature';
    
    // Default daily test limit per user
    const DEFAULT_DAILY_LIMIT = 10;
    
    // Secret rotation window in seconds (1 hour)
    const SECRET_ROTATION_WINDOW = 3600;
    
    /**
     * Verify n8n signature from request header
     * 
     * @param WP_REST_Request $request The REST request
     * @return true|WP_Error True if valid, WP_Error otherwise
     */
    public static function verify_n8n_signature( WP_REST_Request $request ) {
        $signature = $request->get_header( self::SIGNATURE_HEADER );
        
        if ( empty( $signature ) ) {
            return new WP_Error(
                'missing_signature',
                __( 'Missing signature header.', 'gemini-kimi-seo' ),
                [ 'status' => 403 ]
            );
        }
        
        $body = $request->get_body();
        $secret = get_option( 'gkso_shared_secret' );
        
        if ( empty( $secret ) ) {
            return new WP_Error(
                'secret_not_configured',
                __( 'Shared secret not configured.', 'gemini-kimi-seo' ),
                [ 'status' => 403 ]
            );
        }
        
        // Compute expected signature
        $expected = hash_hmac( 'sha256', $body, $secret );
        
        // Timing-safe comparison
        if ( ! hash_equals( $expected, $signature ) ) {
            // Check previous secret during rotation window
            $previous_secret = get_option( 'gkso_shared_secret_previous' );
            $rotation_expiry = get_option( 'gkso_shared_secret_rotation_expiry' );
            
            if ( ! empty( $previous_secret ) && time() < intval( $rotation_expiry ) ) {
                $expected_previous = hash_hmac( 'sha256', $body, $previous_secret );
                
                if ( hash_equals( $expected_previous, $signature ) ) {
                    return true;
                }
            }
            
            return new WP_Error(
                'invalid_signature',
                __( 'Invalid signature.', 'gemini-kimi-seo' ),
                [ 'status' => 403 ]
            );
        }
        
        return true;
    }
    
    /**
     * Get client IP address (Cloudflare + proxy aware)
     * 
     * @return string The client IP address
     */
    public static function get_client_ip() {
        $ip = '';
        
        // Check Cloudflare header
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE ) ) {
                return $ip;
            }
        }
        
        // Check X-Forwarded-For (first IP in comma list)
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            $ip = trim( $ips[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE ) ) {
                return $ip;
            }
        }
        
        // Check X-Real-IP
        if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE ) ) {
                return $ip;
            }
        }
        
        // Fallback to REMOTE_ADDR
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
        
        return '';
    }
    
    /**
     * Check if client IP is in allowlist
     * 
     * @return true|WP_Error True if allowed, WP_Error otherwise
     */
    public static function check_ip_allowlist() {
        // Only run if enabled
        if ( ! get_option( 'gkso_enable_ip_allowlist', false ) ) {
            return true;
        }
        
        $allowlist = get_option( 'gkso_n8n_ip_allowlist', [] );
        
        // Empty allowlist means allow all
        if ( empty( $allowlist ) || ! is_array( $allowlist ) ) {
            return true;
        }
        
        $client_ip = self::get_client_ip();
        
        if ( empty( $client_ip ) ) {
            // Fire action for logging/monitoring
            do_action( 'gkso_ip_blocked', '', 'could_not_determine_ip' );
            
            return new WP_Error(
                'ip_not_determined',
                __( 'Could not determine client IP address.', 'gemini-kimi-seo' ),
                [ 'status' => 403 ]
            );
        }
        
        // Check if IP is in allowlist
        if ( ! in_array( $client_ip, $allowlist, true ) ) {
            // Fire action for logging/monitoring
            do_action( 'gkso_ip_blocked', $client_ip, 'not_in_allowlist' );
            
            return new WP_Error(
                'ip_not_allowed',
                __( 'IP address not in allowlist.', 'gemini-kimi-seo' ),
                [ 'status' => 403 ]
            );
        }
        
        return true;
    }
    
    /**
     * Check user rate limit for daily tests
     * 
     * @param int $user_id The user ID
     * @return true|WP_Error True if under limit, WP_Error otherwise
     */
    public static function check_user_rate_limit( $user_id ) {
        $user_id = intval( $user_id );
        
        if ( $user_id <= 0 ) {
            return new WP_Error(
                'invalid_user',
                __( 'Invalid user ID.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        // Get limit from settings
        $limit = intval( get_option( 'gkso_daily_test_limit_per_user', self::DEFAULT_DAILY_LIMIT ) );
        
        // Build transient key: gkso_tests_{user_id}_{YYYY-MM-DD}
        $date = current_time( 'Y-m-d' );
        $transient_key = 'gkso_tests_' . $user_id . '_' . $date;
        
        // Get current count
        $count = get_transient( $transient_key );
        
        if ( false === $count ) {
            $count = 0;
        } else {
            $count = intval( $count );
        }
        
        // Check if limit exceeded
        if ( $count >= $limit ) {
            // Calculate time until reset (midnight)
            $tomorrow = strtotime( 'tomorrow midnight', current_time( 'timestamp' ) );
            $retry_after = $tomorrow - current_time( 'timestamp' );
            
            return new WP_Error(
                'daily_limit_exceeded',
                sprintf(
                    /* translators: %d: Daily test limit */
                    __( 'Daily test limit of %d exceeded. Please try again tomorrow.', 'gemini-kimi-seo' ),
                    $limit
                ),
                [ 
                    'status'      => 429,
                    'retry_after' => $retry_after,
                    'limit'       => $limit,
                    'used'        => $count,
                ]
            );
        }
        
        return true;
    }
    
    /**
     * Increment user rate limit counter
     * 
     * @param int $user_id The user ID
     * @return bool True on success
     */
    public static function increment_rate_limit( $user_id ) {
        $user_id = intval( $user_id );
        
        if ( $user_id <= 0 ) {
            return false;
        }
        
        // Build transient key
        $date = current_time( 'Y-m-d' );
        $transient_key = 'gkso_tests_' . $user_id . '_' . $date;
        
        // Get current count
        $count = get_transient( $transient_key );
        
        if ( false === $count ) {
            $count = 0;
        } else {
            $count = intval( $count );
        }
        
        // Increment
        $count++;
        
        // Calculate expiration (until midnight)
        $tomorrow = strtotime( 'tomorrow midnight', current_time( 'timestamp' ) );
        $expiration = $tomorrow - current_time( 'timestamp' );
        
        // Save
        set_transient( $transient_key, $count, $expiration );
        
        return true;
    }
    
    /**
     * Sign webhook payload for outbound requests to n8n
     * 
     * @param array $payload The payload to sign
     * @return string The HMAC signature
     */
    public static function sign_webhook_payload( array $payload ) {
        $secret = get_option( 'gkso_shared_secret' );
        
        if ( empty( $secret ) ) {
            // Generate a secret if none exists
            $secret = wp_generate_password( 64, false );
            update_option( 'gkso_shared_secret', $secret );
        }
        
        // Sort payload keys for consistent signing
        ksort( $payload );
        
        $body = wp_json_encode( $payload );
        
        return hash_hmac( 'sha256', $body, $secret );
    }
    
    /**
     * Rotate shared secret
     * Stores previous secret for rotation window
     * 
     * @param string $new_secret The new secret
     * @return bool True on success
     */
    public static function rotate_shared_secret( $new_secret ) {
        // Get current secret
        $current_secret = get_option( 'gkso_shared_secret' );
        
        // Store as previous secret with expiry
        if ( ! empty( $current_secret ) ) {
            update_option( 'gkso_shared_secret_previous', $current_secret );
            update_option( 'gkso_shared_secret_rotation_expiry', time() + self::SECRET_ROTATION_WINDOW );
        }
        
        // Update to new secret
        update_option( 'gkso_shared_secret', sanitize_text_field( $new_secret ) );
        
        // Schedule cleanup of previous secret
        wp_schedule_single_event( time() + self::SECRET_ROTATION_WINDOW, 'gkso_cleanup_previous_secret' );
        
        return true;
    }
    
    /**
     * Cleanup previous secret after rotation window
     * Hooked to 'gkso_cleanup_previous_secret' action
     * 
     * @return void
     */
    public static function cleanup_previous_secret() {
        delete_option( 'gkso_shared_secret_previous' );
        delete_option( 'gkso_shared_secret_rotation_expiry' );
    }
    
    /**
     * Generate a secure shared secret
     * 
     * @param int $length Length of secret (default 64)
     * @return string The generated secret
     */
    public static function generate_secret( $length = 64 ) {
        return wp_generate_password( intval( $length ), false );
    }
    
    /**
     * Verify nonce for admin AJAX requests
     * 
     * @param string $nonce  The nonce to verify
     * @param string $action The nonce action
     * @return true|WP_Error True if valid, WP_Error otherwise
     */
    public static function verify_nonce( $nonce, $action ) {
        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            return new WP_Error(
                'invalid_nonce',
                __( 'Security check failed. Please refresh the page and try again.', 'gemini-kimi-seo' ),
                [ 'status' => 403 ]
            );
        }
        
        return true;
    }
    
    /**
     * Check if user has required capability
     * 
     * @param string $capability The capability to check
     * @param int    $object_id  Optional object ID
     * @return true|WP_Error True if has cap, WP_Error otherwise
     */
    public static function check_capability( $capability, $object_id = null ) {
        if ( null !== $object_id ) {
            $has_cap = current_user_can( $capability, $object_id );
        } else {
            $has_cap = current_user_can( $capability );
        }
        
        if ( ! $has_cap ) {
            return new WP_Error(
                'insufficient_permissions',
                __( 'You do not have permission to perform this action.', 'gemini-kimi-seo' ),
                [ 'status' => 403 ]
            );
        }
        
        return true;
    }
}

// Register cleanup hook
add_action( 'gkso_cleanup_previous_secret', [ 'GKSO_Security', 'cleanup_previous_secret' ] );
