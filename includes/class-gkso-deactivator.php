<?php
/**
 * GKSO_Deactivator class
 * 
 * Handles plugin deactivation: cleanup scheduled events, flush rewrite rules.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_Deactivator {
    
    /**
     * Deactivate the plugin
     */
    public static function deactivate() {
        // Clear scheduled events
        self::clear_scheduled_events();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Fire deactivation hook
        do_action( 'gkso_deactivated' );
    }
    
    /**
     * Clear all scheduled events
     */
    private static function clear_scheduled_events() {
        // Clear secret rotation cleanup events
        $timestamp = wp_next_scheduled( 'gkso_cleanup_previous_secret' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'gkso_cleanup_previous_secret' );
        }
        
        // Clear any bulk action scheduled events
        global $wpdb;
        
        // Get all scheduled events with gkso_ prefix
        $crons = _get_cron_array();
        
        if ( ! empty( $crons ) ) {
            foreach ( $crons as $timestamp => $cron ) {
                foreach ( $cron as $hook => $events ) {
                    if ( strpos( $hook, 'gkso_' ) === 0 ) {
                        foreach ( $events as $event ) {
                            wp_unschedule_event( $timestamp, $hook, $event['args'] );
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Remove capabilities (called on uninstall, not deactivation)
     */
    public static function remove_capabilities() {
        $role = get_role( 'administrator' );
        
        if ( $role ) {
            $role->remove_cap( 'seo_optimize' );
            $role->remove_cap( 'seo_view_tests' );
        }
        
        $editor_role = get_role( 'editor' );
        if ( $editor_role ) {
            $editor_role->remove_cap( 'seo_view_tests' );
        }
    }
}
