<?php
/**
 * GKSO_Webhook class
 * 
 * Constructs and sends the full payload to n8n on test initiation.
 * Handles timeouts, retries, and parses the acknowledgment.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_Webhook {
    
    // Request timeout in seconds
    const REQUEST_TIMEOUT = 30;
    
    // Maximum retry attempts
    const MAX_RETRIES = 3;
    
    // Retry delay in seconds
    const RETRY_DELAY = 5;
    
    /**
     * Dispatch webhook to n8n
     * 
     * @param int   $post_id The post ID
     * @param array $params  Additional parameters
     * @return array|WP_Error Response data or error
     */
    public static function dispatch_to_n8n( $post_id, $params = [] ) {
        $post = get_post( $post_id );
        
        if ( ! $post ) {
            return new WP_Error(
                'post_not_found',
                __( 'Post not found.', 'gemini-kimi-seo' ),
                [ 'status' => 404 ]
            );
        }
        
        // Get n8n webhook URL from settings
        $webhook_url = get_option( 'gkso_n8n_webhook_url' );
        
        if ( empty( $webhook_url ) ) {
            return new WP_Error(
                'webhook_not_configured',
                __( 'n8n webhook URL not configured.', 'gemini-kimi-seo' ),
                [ 'status' => 500 ]
            );
        }
        
        // Build payload
        $payload = self::build_payload( $post, $params );
        
        // Sign the payload
        $signature = GKSO_Security::sign_webhook_payload( $payload );
        $payload['signature'] = $signature;
        
        // Send request with retries
        $attempt = 0;
        $last_error = null;
        
        while ( $attempt < self::MAX_RETRIES ) {
            $attempt++;
            
            $response = self::send_request( $webhook_url, $payload );
            
            if ( ! is_wp_error( $response ) ) {
                // Success
                return $response;
            }
            
            $last_error = $response;
            
            // Log retry attempt
            error_log( sprintf(
                'GKSO: Webhook attempt %d/%d failed for post %d: %s',
                $attempt,
                self::MAX_RETRIES,
                $post_id,
                $response->get_error_message()
            ) );
            
            // Wait before retry (except on last attempt)
            if ( $attempt < self::MAX_RETRIES ) {
                sleep( self::RETRY_DELAY );
            }
        }
        
        // All retries exhausted
        return new WP_Error(
            'webhook_failed',
            sprintf(
                /* translators: %d: Number of retry attempts */
                __( 'Webhook failed after %d attempts: ', 'gemini-kimi-seo' ),
                self::MAX_RETRIES
            ) . $last_error->get_error_message(),
            [ 'status' => 502 ]
        );
    }
    
    /**
     * Build the webhook payload
     * 
     * @param WP_Post $post   The post object
     * @param array   $params Additional parameters
     * @return array The payload
     */
    private static function build_payload( WP_Post $post, $params = [] ) {
        $post_id = $post->ID;
        
        // Get current SEO meta
        $current_seo = GKSO_SEO_Integrations::get_current_seo_meta( $post_id );
        
        // Get test parameters
        $manual_override = ! empty( $params['manual_override'] );
        $priority = ! empty( $params['priority'] ) ? sanitize_text_field( $params['priority'] ) : 'normal';
        $preferred_model = ! empty( $params['preferred_model'] ) ? sanitize_text_field( $params['preferred_model'] ) : 'auto';
        
        // Get baseline metrics if available
        $baseline_metrics = [
            'ctr'         => floatval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::BASELINE_CTR, 0 ) ),
            'ctr_std'     => floatval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::BASELINE_CTR_STD, 0 ) ),
            'position'    => floatval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::BASELINE_POSITION, 0 ) ),
            'pageviews'   => intval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::BASELINE_PAGEVIEWS, 0 ) ),
            'impressions' => intval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::BASELINE_IMPRESSIONS, 0 ) ),
            'date_range'  => GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::BASELINE_DATE_RANGE, '' ),
        ];
        
        // Build payload
        $payload = [
            'post_id'           => $post_id,
            'site_url'          => get_site_url(),
            'post_url'          => get_permalink( $post_id ),
            'post_title'        => $post->post_title,
            'post_excerpt'      => GKSO_SEO_Integrations::get_content_excerpt( $post ),
            'current_seo'       => $current_seo,
            'baseline_metrics'  => $baseline_metrics,
            'trigger_type'      => $manual_override ? 'manual' : 'scheduled',
            'priority'          => $priority,
            'preferred_model'   => $preferred_model,
            'webhook_timestamp' => current_time( 'c' ),
            'callback_url'      => rest_url( GKSO_REST_NAMESPACE . '/update-meta' ),
            'status_url'        => rest_url( GKSO_REST_NAMESPACE . '/test-status/' . $post_id ),
            'wordpress_version' => get_bloginfo( 'version' ),
            'plugin_version'    => GKSO_VERSION,
        ];
        
        // Allow filtering of payload
        return apply_filters( 'gkso_webhook_payload', $payload, $post_id );
    }
    
    /**
     * Send HTTP request to n8n webhook
     * 
     * @param string $url     The webhook URL
     * @param array  $payload The payload
     * @return array|WP_Error Response data or error
     */
    private static function send_request( $url, $payload ) {
        $args = [
            'method'      => 'POST',
            'timeout'     => self::REQUEST_TIMEOUT,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'X-GKSO-Source' => 'wordpress',
            ],
            'body'        => wp_json_encode( $payload ),
            'cookies'     => [],
            'sslverify'   => true,
        ];
        
        // Allow filtering of request args
        $args = apply_filters( 'gkso_webhook_request_args', $args, $url, $payload );
        
        $response = wp_remote_post( $url, $args );
        
        // Check for HTTP errors
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'webhook_request_failed',
                $response->get_error_message(),
                [ 'status' => 502 ]
            );
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        
        // Check response code
        if ( $response_code < 200 || $response_code >= 300 ) {
            return new WP_Error(
                'webhook_rejected',
                sprintf(
                    /* translators: %d: HTTP response code */
                    __( 'Webhook returned HTTP %d: %s', 'gemini-kimi-seo' ),
                    $response_code,
                    substr( $response_body, 0, 200 )
                ),
                [ 
                    'status' => 502,
                    'code'   => $response_code,
                    'body'   => $response_body,
                ]
            );
        }
        
        // Parse response body
        $data = json_decode( $response_body, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            // Not JSON, but request succeeded
            return [
                'success'      => true,
                'raw_response' => $response_body,
                'test_id'      => self::generate_test_id(),
            ];
        }
        
        // Extract test_id from response or generate fallback
        $test_id = ! empty( $data['test_id'] ) ? sanitize_text_field( $data['test_id'] ) : self::generate_test_id();
        
        return [
            'success'      => true,
            'test_id'      => $test_id,
            'response'     => $data,
            'raw_response' => $response_body,
        ];
    }
    
    /**
     * Generate a test ID (UUID v4)
     * 
     * @return string UUID v4
     */
    private static function generate_test_id() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            wp_rand( 0, 0xffff ),
            wp_rand( 0, 0xffff ),
            wp_rand( 0, 0xffff ),
            wp_rand( 0, 0x0fff ) | 0x4000,
            wp_rand( 0, 0x3fff ) | 0x8000,
            wp_rand( 0, 0xffff ),
            wp_rand( 0, 0xffff ),
            wp_rand( 0, 0xffff )
        );
    }
    
    /**
     * Handle incoming webhook from n8n (for future bidirectional communication)
     * 
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response The response
     */
    public static function handle_incoming_webhook( WP_REST_Request $request ) {
        // Verify signature
        $signature_check = GKSO_Security::verify_n8n_signature( $request );
        
        if ( is_wp_error( $signature_check ) ) {
            return new WP_REST_Response( [ 'error' => $signature_check->get_error_message() ], 403 );
        }
        
        // Check IP allowlist if enabled
        $ip_check = GKSO_Security::check_ip_allowlist();
        
        if ( is_wp_error( $ip_check ) ) {
            return new WP_REST_Response( [ 'error' => $ip_check->get_error_message() ], 403 );
        }
        
        $body = $request->get_json_params();
        
        // Process webhook data
        do_action( 'gkso_incoming_webhook', $body, $request );
        
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }
    
    /**
     * Test webhook connectivity
     * 
     * @return array|WP_Error Test result or error
     */
    public static function test_connection() {
        $webhook_url = get_option( 'gkso_n8n_webhook_url' );
        
        if ( empty( $webhook_url ) ) {
            return new WP_Error(
                'webhook_not_configured',
                __( 'n8n webhook URL not configured.', 'gemini-kimi-seo' ),
                [ 'status' => 500 ]
            );
        }
        
        $payload = [
            'event'     => 'test',
            'timestamp' => current_time( 'c' ),
            'site_url'  => get_site_url(),
        ];
        
        $signature = GKSO_Security::sign_webhook_payload( $payload );
        $payload['signature'] = $signature;
        
        $args = [
            'method'      => 'POST',
            'timeout'     => 15,
            'headers'     => [
                'Content-Type' => 'application/json',
            ],
            'body'        => wp_json_encode( $payload ),
            'sslverify'   => true,
        ];
        
        $response = wp_remote_post( $webhook_url, $args );
        
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'connection_failed',
                $response->get_error_message(),
                [ 'status' => 502 ]
            );
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        
        return [
            'success'       => $response_code >= 200 && $response_code < 300,
            'response_code' => $response_code,
            'message'       => sprintf(
                /* translators: %d: HTTP response code */
                __( 'Connection test returned HTTP %d', 'gemini-kimi-seo' ),
                $response_code
            ),
        ];
    }
}
