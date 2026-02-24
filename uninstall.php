<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Gemini-Kimi SEO Optimizer
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Load the plugin to access classes
require_once plugin_dir_path( __FILE__ ) . 'gemini-kimi-seo-optimizer.php';

/**
 * Delete all plugin data
 */
function gkso_uninstall() {
    global $wpdb;
    
    // Remove capabilities
    $role = get_role( 'administrator' );
    if ( $role ) {
        $role->remove_cap( 'seo_optimize' );
        $role->remove_cap( 'seo_view_tests' );
    }
    
    $editor_role = get_role( 'editor' );
    if ( $editor_role ) {
        $editor_role->remove_cap( 'seo_view_tests' );
    }
    
    // Delete all plugin options
    $options = [
        'gkso_shared_secret',
        'gkso_shared_secret_previous',
        'gkso_shared_secret_rotation_expiry',
        'gkso_n8n_webhook_url',
        'gkso_daily_test_limit_per_user',
        'gkso_enabled_post_types',
        'gkso_cooldown_days',
        'gkso_test_duration_days',
        'gkso_enable_ip_allowlist',
        'gkso_n8n_ip_allowlist',
    ];
    
    foreach ( $options as $option ) {
        delete_option( $option );
    }
    
    // Delete all post meta
    $meta_keys = GKSO_Meta_Schema::get_all_keys();
    
    foreach ( $meta_keys as $meta_key ) {
        $wpdb->delete(
            $wpdb->postmeta,
            [ 'meta_key' => $meta_key ],
            [ '%s' ]
        );
    }
    
    // Clean up transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gkso_%' OR option_name LIKE '_transient_timeout_gkso_%'"
    );
    
    // Clear scheduled events
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
    
    // Fire uninstall action
    do_action( 'gkso_uninstalled' );
}

// Run uninstall
if ( ! is_multisite() ) {
    gkso_uninstall();
} else {
    // Multisite uninstall
    $site_ids = get_sites( [ 'fields' => 'ids' ] );
    
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        gkso_uninstall();
        restore_current_blog();
    }
}
