<?php
/**
 * GKSO_Meta_Schema class
 * 
 * Defines all post meta keys used by the SEO optimizer system.
 * Single source of truth for state, baseline snapshots, test variants, and history.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_Meta_Schema {
    
    // Core State Meta Keys
    const STATUS                    = '_seo_ab_test_status';
    const STARTED                   = '_seo_ab_test_started';
    const VERSION                   = '_seo_ab_test_version';
    const TEST_ID                   = '_seo_test_id';
    
    // Baseline Metrics Meta Keys
    const BASELINE_CTR              = '_seo_baseline_ctr';
    const BASELINE_CTR_STD          = '_seo_baseline_ctr_std';
    const BASELINE_POSITION         = '_seo_baseline_position';
    const BASELINE_PAGEVIEWS        = '_seo_baseline_pageviews';
    const BASELINE_IMPRESSIONS      = '_seo_baseline_impressions';
    const BASELINE_DATE_RANGE       = '_seo_baseline_date_range';
    
    // Test Variant Meta Keys
    const TEST_TITLE                = '_seo_test_title';
    const TEST_DESCRIPTION          = '_seo_test_description';
    const TEST_AI_MODEL             = '_seo_test_ai_model';
    const TEST_PROMPT_HASH          = '_seo_test_generation_prompt_hash';
    const TEST_SNAPSHOTS            = '_seo_test_snapshots';
    const TEST_HISTORY              = '_seo_test_history';
    
    // Failure/Termination Meta Keys
    const TERMINATION_REASON        = '_seo_termination_reason';
    const ERROR_LOG                 = '_seo_error_log';
    
    /**
     * Get all meta keys as an array
     * 
     * @return array List of all meta key constants
     */
    public static function get_all_keys() {
        return [
            self::STATUS,
            self::STARTED,
            self::VERSION,
            self::TEST_ID,
            self::BASELINE_CTR,
            self::BASELINE_CTR_STD,
            self::BASELINE_POSITION,
            self::BASELINE_PAGEVIEWS,
            self::BASELINE_IMPRESSIONS,
            self::BASELINE_DATE_RANGE,
            self::TEST_TITLE,
            self::TEST_DESCRIPTION,
            self::TEST_AI_MODEL,
            self::TEST_PROMPT_HASH,
            self::TEST_SNAPSHOTS,
            self::TEST_HISTORY,
            self::TERMINATION_REASON,
            self::ERROR_LOG,
        ];
    }
    
    /**
     * Sanitize a value based on the meta key type
     * 
     * @param string $key   The meta key
     * @param mixed  $value The value to sanitize
     * @return mixed Sanitized value
     */
    public static function sanitize( $key, $value ) {
        switch ( $key ) {
            // String meta keys (max 70 chars for title)
            case self::TEST_TITLE:
                return sanitize_text_field( substr( $value, 0, 70 ) );
                
            // String meta keys (max 320 chars for description)
            case self::TEST_DESCRIPTION:
                return sanitize_textarea_field( substr( $value, 0, 320 ) );
                
            // Status enum
            case self::STATUS:
                $allowed = [ 'Baseline', 'Testing', 'Optimized', 'Failed' ];
                return in_array( $value, $allowed, true ) ? $value : 'Baseline';
                
            // AI Model enum
            case self::TEST_AI_MODEL:
                $allowed = [ 'gemini', 'kimi', 'ensemble' ];
                return in_array( $value, $allowed, true ) ? $value : 'gemini';
                
            // Integer meta keys
            case self::VERSION:
            case self::BASELINE_PAGEVIEWS:
            case self::BASELINE_IMPRESSIONS:
                return absint( $value );
                
            // Float meta keys
            case self::BASELINE_CTR:
            case self::BASELINE_CTR_STD:
            case self::BASELINE_POSITION:
                return floatval( $value );
                
            // Date/DateTime meta keys (ISO 8601)
            case self::STARTED:
            case self::BASELINE_DATE_RANGE:
                // Validate ISO 8601 format
                if ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
                    return sanitize_text_field( $value );
                }
                return '';
                
            // UUID meta keys
            case self::TEST_ID:
                // Validate UUID v4 format
                if ( is_string( $value ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value ) ) {
                    return strtolower( sanitize_text_field( $value ) );
                }
                return '';
                
            // Hash meta keys (SHA-256)
            case self::TEST_PROMPT_HASH:
                if ( is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/i', $value ) ) {
                    return strtolower( sanitize_text_field( $value ) );
                }
                return '';
                
            // JSON meta keys
            case self::TEST_SNAPSHOTS:
            case self::TEST_HISTORY:
                if ( is_array( $value ) ) {
                    return wp_json_encode( $value );
                }
                if ( is_string( $value ) ) {
                    // Validate JSON
                    json_decode( $value );
                    if ( json_last_error() === JSON_ERROR_NONE ) {
                        return $value;
                    }
                }
                return wp_json_encode( [] );
                
            // Text meta keys
            case self::TERMINATION_REASON:
            case self::ERROR_LOG:
                return sanitize_textarea_field( $value );
                
            default:
                return sanitize_text_field( $value );
        }
    }
    
    /**
     * Get the test history for a post
     * 
     * @param int $post_id The post ID
     * @return array Decoded history array (max 10 records)
     */
    public static function get_history( $post_id ) {
        $history = get_post_meta( $post_id, self::TEST_HISTORY, true );
        if ( empty( $history ) ) {
            return [];
        }
        
        $decoded = json_decode( $history, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            return [];
        }
        
        return $decoded;
    }
    
    /**
     * Append a record to the test history
     * Maintains maximum of 10 records (FIFO)
     * 
     * @param int   $post_id The post ID
     * @param array $record  The record to append
     * @return bool True on success, false on failure
     */
    public static function append_history( $post_id, $record ) {
        $history = self::get_history( $post_id );
        
        // Add timestamp to record
        $record['timestamp'] = current_time( 'c' );
        
        // Append new record
        $history[] = $record;
        
        // Keep only last 10 records
        if ( count( $history ) > 10 ) {
            $history = array_slice( $history, -10 );
        }
        
        // Save back to post meta
        $sanitized = self::sanitize( self::TEST_HISTORY, $history );
        return update_post_meta( $post_id, self::TEST_HISTORY, $sanitized );
    }
    
    /**
     * Get a meta value with proper sanitization
     * 
     * @param int    $post_id The post ID
     * @param string $key     The meta key (use class constants)
     * @param mixed  $default Default value if meta doesn't exist
     * @return mixed The sanitized meta value
     */
    public static function get_meta( $post_id, $key, $default = '' ) {
        $value = get_post_meta( $post_id, $key, true );
        
        if ( $value === '' || $value === false ) {
            return $default;
        }
        
        // For JSON fields, decode them
        if ( in_array( $key, [ self::TEST_SNAPSHOTS, self::TEST_HISTORY ], true ) ) {
            $decoded = json_decode( $value, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                return $decoded;
            }
            return $default !== '' ? $default : [];
        }
        
        return $value;
    }
    
    /**
     * Update a meta value with proper sanitization
     * 
     * @param int    $post_id The post ID
     * @param string $key     The meta key (use class constants)
     * @param mixed  $value   The value to store
     * @return bool True on success, false on failure
     */
    public static function update_meta( $post_id, $key, $value ) {
        $sanitized = self::sanitize( $key, $value );
        return update_post_meta( $post_id, $key, $sanitized );
    }
    
    /**
     * Delete a meta value
     * 
     * @param int    $post_id The post ID
     * @param string $key     The meta key (use class constants)
     * @return bool True on success, false on failure
     */
    public static function delete_meta( $post_id, $key ) {
        return delete_post_meta( $post_id, $key );
    }
    
    /**
     * Delete all plugin meta for a post
     * 
     * @param int $post_id The post ID
     * @return bool True on success, false on failure
     */
    public static function delete_all_meta( $post_id ) {
        $keys = self::get_all_keys();
        $success = true;
        
        foreach ( $keys as $key ) {
            if ( ! delete_post_meta( $post_id, $key ) ) {
                // If the key didn't exist, that's okay
                if ( get_post_meta( $post_id, $key, true ) !== '' ) {
                    $success = false;
                }
            }
        }
        
        return $success;
    }
}
