<?php
/**
 * GKSO_State_Machine class
 * 
 * Enforces valid state transitions between Baseline → Testing → Optimized/Failed.
 * Prevents concurrent tests and handles lock expiration.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_State_Machine {
    
    // Lock timeout: 14-day test + 2-day grace period = 16 days
    const LOCK_TIMEOUT_SECONDS = 16 * DAY_IN_SECONDS;
    
    // Cooldown period after optimization: 30 days
    const COOLDOWN_SECONDS = 30 * DAY_IN_SECONDS;
    
    /**
     * Get current status for a post
     * 
     * @param int $post_id The post ID
     * @return string Current status (Baseline, Testing, Optimized, Failed)
     */
    public static function get_status( $post_id ) {
        $status = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::STATUS, 'Baseline' );
        
        // Validate status
        $allowed = [ 'Baseline', 'Testing', 'Optimized', 'Failed' ];
        if ( ! in_array( $status, $allowed, true ) ) {
            return 'Baseline';
        }
        
        return $status;
    }
    
    /**
     * Check if a new test can be initiated
     * 
     * @param int  $post_id        The post ID
     * @param bool $manual_override Whether to override cooldown period
     * @return true|WP_Error True if test can start, WP_Error otherwise
     */
    public static function can_initiate_test( $post_id, $manual_override = false ) {
        $current_status = self::get_status( $post_id );
        
        // Check if currently testing
        if ( $current_status === 'Testing' ) {
            // Check if lock has expired
            if ( self::is_lock_expired( $post_id ) ) {
                // Lock expired, allow new test
                return true;
            }
            
            return new WP_Error(
                'test_in_progress',
                __( 'A test is already in progress for this post.', 'gemini-kimi-seo' ),
                [ 'status' => 409 ]
            );
        }
        
        // Check cooldown period for Optimized status
        if ( $current_status === 'Optimized' && ! $manual_override ) {
            $last_test = self::get_last_test_timestamp( $post_id );
            
            if ( $last_test ) {
                $cooldown_end = $last_test + self::COOLDOWN_SECONDS;
                
                if ( time() < $cooldown_end ) {
                    $days_remaining = ceil( ( $cooldown_end - time() ) / DAY_IN_SECONDS );
                    
                    return new WP_Error(
                        'cooldown_active',
                        sprintf(
                            /* translators: %d: Number of days remaining in cooldown */
                            __( 'Cooldown period active. Please wait %d days before starting a new test, or use manual override.', 'gemini-kimi-seo' ),
                            $days_remaining
                        ),
                        [ 
                            'status'      => 429,
                            'retry_after' => $cooldown_end - time()
                        ]
                    );
                }
            }
        }
        
        // Baseline, Failed, or Optimized (with override/cooldown passed) can start new test
        $allowed_statuses = [ 'Baseline', 'Failed', 'Optimized' ];
        if ( ! in_array( $current_status, $allowed_statuses, true ) ) {
            return new WP_Error(
                'invalid_status',
                __( 'Invalid post status for initiating a test.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        return true;
    }
    
    /**
     * Transition to Testing state
     * Atomic update with race condition protection
     * 
     * @param int    $post_id The post ID
     * @param string $test_id The unique test ID
     * @param array  $params  Additional parameters (title, description, ai_model, prompt_hash)
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public static function transition_to_testing( $post_id, $test_id, $params = [] ) {
        global $wpdb;
        
        // First, verify we can start a test
        $can_start = self::can_initiate_test( $post_id );
        if ( is_wp_error( $can_start ) ) {
            return $can_start;
        }
        
        // Generate UUID if not provided
        if ( empty( $test_id ) || ! is_string( $test_id ) ) {
            $test_id = self::generate_uuid();
        }
        
        // Get current version and increment
        $current_version = intval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::VERSION, 0 ) );
        $new_version = $current_version + 1;
        
        $now = current_time( 'c' );
        
        // Use direct database query for atomic update
        $meta_table = $wpdb->postmeta;
        
        // Start transaction for atomicity
        $wpdb->query( 'START TRANSACTION' );
        
        try {
            // Check current status again (race condition protection)
            $current_status = $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_value FROM {$meta_table} WHERE post_id = %d AND meta_key = %s",
                $post_id,
                GKSO_Meta_Schema::STATUS
            ) );
            
            // If status is Testing and lock hasn't expired, another process won
            if ( $current_status === 'Testing' && ! self::is_lock_expired( $post_id ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error(
                    'test_in_progress',
                    __( 'Another test was initiated concurrently. Please try again.', 'gemini-kimi-seo' ),
                    [ 'status' => 409 ]
                );
            }
            
            // Update all meta fields atomically
            $updates = [
                GKSO_Meta_Schema::STATUS    => 'Testing',
                GKSO_Meta_Schema::STARTED   => $now,
                GKSO_Meta_Schema::VERSION   => $new_version,
                GKSO_Meta_Schema::TEST_ID   => $test_id,
            ];
            
            // Add test variant data if provided
            if ( ! empty( $params['test_title'] ) ) {
                $updates[ GKSO_Meta_Schema::TEST_TITLE ] = $params['test_title'];
            }
            if ( ! empty( $params['test_description'] ) ) {
                $updates[ GKSO_Meta_Schema::TEST_DESCRIPTION ] = $params['test_description'];
            }
            if ( ! empty( $params['ai_model'] ) ) {
                $updates[ GKSO_Meta_Schema::TEST_AI_MODEL ] = $params['ai_model'];
            }
            if ( ! empty( $params['prompt_hash'] ) ) {
                $updates[ GKSO_Meta_Schema::TEST_PROMPT_HASH ] = $params['prompt_hash'];
            }
            
            // Clear previous test snapshots
            $updates[ GKSO_Meta_Schema::TEST_SNAPSHOTS ] = [];
            
            // Clear termination reason if any
            delete_post_meta( $post_id, GKSO_Meta_Schema::TERMINATION_REASON );
            
            // Apply all updates
            foreach ( $updates as $key => $value ) {
                $sanitized = GKSO_Meta_Schema::sanitize( $key, $value );
                update_post_meta( $post_id, $key, $sanitized );
            }
            
            // Set application-level cache lock as secondary guard
            $cache_key = 'gkso_test_lock_' . $post_id;
            wp_cache_set( $cache_key, $test_id, '', HOUR_IN_SECONDS );
            
            $wpdb->query( 'COMMIT' );
            
            // Fire action for hooks
            do_action( 'gkso_transition_to_testing', $post_id, $test_id, $new_version );
            
            return true;
            
        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error(
                'transition_failed',
                $e->getMessage(),
                [ 'status' => 500 ]
            );
        }
    }
    
    /**
     * Transition to Optimized state
     * Called by n8n callback when test wins
     * 
     * @param int    $post_id      The post ID
     * @param string $final_title  The winning title
     * @param string $final_desc   The winning description
     * @param array  $metrics      Final test metrics
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public static function transition_to_optimized( $post_id, $final_title, $final_desc, $metrics = [] ) {
        $current_status = self::get_status( $post_id );
        
        if ( $current_status !== 'Testing' ) {
            return new WP_Error(
                'invalid_transition',
                __( 'Cannot transition to Optimized: post is not in Testing state.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        $test_id = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_ID );
        $version = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::VERSION, 1 );
        $ai_model = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_AI_MODEL, 'unknown' );
        
        // Update SEO meta via integrations
        $seo_result = GKSO_SEO_Integrations::update_seo_meta( $post_id, $final_title, $final_desc );
        
        if ( is_wp_error( $seo_result ) ) {
            return $seo_result;
        }
        
        // Build history record
        $history_record = [
            'test_id'         => $test_id,
            'version'         => $version,
            'result'          => 'optimized',
            'ai_model'        => $ai_model,
            'final_title'     => $final_title,
            'final_desc'      => $final_desc,
            'metrics'         => $metrics,
            'completed_at'    => current_time( 'c' ),
        ];
        
        // Append to history
        GKSO_Meta_Schema::append_history( $post_id, $history_record );
        
        // Update status
        GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::STATUS, 'Optimized' );
        
        // Clear test-specific meta (keep history)
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_TITLE );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_DESCRIPTION );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_PROMPT_HASH );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_SNAPSHOTS );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TERMINATION_REASON );
        
        // Clear cache lock
        $cache_key = 'gkso_test_lock_' . $post_id;
        wp_cache_delete( $cache_key );
        
        // Fire action
        do_action( 'gkso_transition_to_optimized', $post_id, $test_id, $metrics );
        
        return true;
    }
    
    /**
     * Transition to Baseline state (test failed to beat baseline)
     * 
     * @param int   $post_id The post ID
     * @param array $metrics Final test metrics
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public static function transition_to_baseline( $post_id, $metrics = [] ) {
        $current_status = self::get_status( $post_id );
        
        if ( $current_status !== 'Testing' ) {
            return new WP_Error(
                'invalid_transition',
                __( 'Cannot revert to Baseline: post is not in Testing state.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        $test_id = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_ID );
        $version = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::VERSION, 1 );
        $ai_model = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_AI_MODEL, 'unknown' );
        
        // Build history record
        $history_record = [
            'test_id'         => $test_id,
            'version'         => $version,
            'result'          => 'baseline',
            'ai_model'        => $ai_model,
            'metrics'         => $metrics,
            'completed_at'    => current_time( 'c' ),
        ];
        
        // Append to history
        GKSO_Meta_Schema::append_history( $post_id, $history_record );
        
        // Update status
        GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::STATUS, 'Baseline' );
        
        // Clear all test meta
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_TITLE );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_DESCRIPTION );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_AI_MODEL );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_PROMPT_HASH );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_SNAPSHOTS );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_ID );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TERMINATION_REASON );
        
        // Clear cache lock
        $cache_key = 'gkso_test_lock_' . $post_id;
        wp_cache_delete( $cache_key );
        
        // Fire action
        do_action( 'gkso_transition_to_baseline', $post_id, $test_id, $metrics );
        
        return true;
    }
    
    /**
     * Transition to Failed state
     * Error during test - preserves state for debugging
     * 
     * @param int    $post_id The post ID
     * @param string $reason  Failure reason
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public static function transition_to_failed( $post_id, $reason ) {
        $current_status = self::get_status( $post_id );
        
        if ( $current_status !== 'Testing' ) {
            return new WP_Error(
                'invalid_transition',
                __( 'Cannot mark as Failed: post is not in Testing state.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        $test_id = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_ID );
        $version = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::VERSION, 1 );
        $ai_model = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_AI_MODEL, 'unknown' );
        
        // Build history record
        $history_record = [
            'test_id'         => $test_id,
            'version'         => $version,
            'result'          => 'failed',
            'ai_model'        => $ai_model,
            'failure_reason'  => $reason,
            'completed_at'    => current_time( 'c' ),
        ];
        
        // Append to history
        GKSO_Meta_Schema::append_history( $post_id, $history_record );
        
        // Update status and store reason
        GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::STATUS, 'Failed' );
        GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::TERMINATION_REASON, $reason );
        
        // Clear cache lock
        $cache_key = 'gkso_test_lock_' . $post_id;
        wp_cache_delete( $cache_key );
        
        // Fire action
        do_action( 'gkso_transition_to_failed', $post_id, $test_id, $reason );
        
        return true;
    }
    
    /**
     * Release an expired lock
     * Force-reset status back to Baseline
     * 
     * @param int $post_id The post ID
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public static function release_lock( $post_id ) {
        $current_status = self::get_status( $post_id );
        
        if ( $current_status !== 'Testing' ) {
            return new WP_Error(
                'no_lock',
                __( 'No active lock to release.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        if ( ! self::is_lock_expired( $post_id ) ) {
            return new WP_Error(
                'lock_not_expired',
                __( 'Lock has not expired yet.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        // Archive the expired test
        $test_id = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_ID );
        $version = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::VERSION, 1 );
        
        $history_record = [
            'test_id'         => $test_id,
            'version'         => $version,
            'result'          => 'expired',
            'reason'          => 'Lock expired after ' . self::LOCK_TIMEOUT_SECONDS . ' seconds',
            'completed_at'    => current_time( 'c' ),
        ];
        
        GKSO_Meta_Schema::append_history( $post_id, $history_record );
        
        // Reset to Baseline
        GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::STATUS, 'Baseline' );
        
        // Clear test meta
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_TITLE );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_DESCRIPTION );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_AI_MODEL );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_PROMPT_HASH );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_SNAPSHOTS );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TEST_ID );
        delete_post_meta( $post_id, GKSO_Meta_Schema::TERMINATION_REASON );
        
        // Clear cache lock
        $cache_key = 'gkso_test_lock_' . $post_id;
        wp_cache_delete( $cache_key );
        
        do_action( 'gkso_lock_released', $post_id, $test_id );
        
        return true;
    }
    
    /**
     * Check if the current lock has expired
     * 
     * @param int $post_id The post ID
     * @return bool True if lock has expired
     */
    public static function is_lock_expired( $post_id ) {
        $started = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::STARTED );
        
        if ( empty( $started ) ) {
            return true;
        }
        
        $started_timestamp = strtotime( $started );
        if ( false === $started_timestamp ) {
            return true;
        }
        
        return ( time() - $started_timestamp ) > self::LOCK_TIMEOUT_SECONDS;
    }
    
    /**
     * Get the timestamp of the last completed test
     * 
     * @param int $post_id The post ID
     * @return int|false Timestamp or false if no history
     */
    private static function get_last_test_timestamp( $post_id ) {
        $history = GKSO_Meta_Schema::get_history( $post_id );
        
        if ( empty( $history ) ) {
            return false;
        }
        
        // Get the most recent record
        $last_record = end( $history );
        
        if ( ! empty( $last_record['completed_at'] ) ) {
            return strtotime( $last_record['completed_at'] );
        }
        
        if ( ! empty( $last_record['timestamp'] ) ) {
            return strtotime( $last_record['timestamp'] );
        }
        
        return false;
    }
    
    /**
     * Generate a UUID v4
     * 
     * @return string UUID v4
     */
    private static function generate_uuid() {
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
}
