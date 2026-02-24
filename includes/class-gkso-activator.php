<?php
/**
 * GKSO_Activator class
 *
 * Handles plugin activation: version checks, capabilities, default options, shared secret.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_Activator {

    /**
     * Activate the plugin
     */
    public static function activate() {
        // Check PHP version
        if ( version_compare( PHP_VERSION, GKSO_MIN_PHP, '<' ) ) {
            deactivate_plugins( plugin_basename( GKSO_PLUGIN_DIR . 'gemini-kimi-seo-optimizer.php' ) );
            wp_die(
                esc_html__( 'Gemini-Kimi SEO Optimizer requires PHP ', 'gemini-kimi-seo' ) . esc_html( GKSO_MIN_PHP ) .
                esc_html__( ' or higher. Please upgrade your PHP version.', 'gemini-kimi-seo' ),
                esc_html__( 'Plugin Activation Error', 'gemini-kimi-seo' ),
                [ 'back_link' => true ]
            );
        }

        // Check WordPress version
        if ( version_compare( get_bloginfo( 'version' ), GKSO_MIN_WP, '<' ) ) {
            deactivate_plugins( plugin_basename( GKSO_PLUGIN_DIR . 'gemini-kimi-seo-optimizer.php' ) );
            wp_die(
                esc_html__( 'Gemini-Kimi SEO Optimizer requires WordPress ', 'gemini-kimi-seo' ) . esc_html( GKSO_MIN_WP ) .
                esc_html__( ' or higher. Please upgrade your WordPress installation.', 'gemini-kimi-seo' ),
                esc_html__( 'Plugin Activation Error', 'gemini-kimi-seo' ),
                [ 'back_link' => true ]
            );
        }

        // Add capabilities to administrator role
        self::add_capabilities();

        // Set default options
        self::set_default_options();

        // Generate shared secret
        self::generate_shared_secret();

        // Create database index for performance
        self::create_database_index();

        // Create internal linking tables (note: method is create_tables, plural)
        GKSO_Internal_Linking::create_tables();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Store activation version
        update_option( 'gkso_version', GKSO_VERSION );

        // Fire activation hook
        do_action( 'gkso_activated' );
    }

    /**
     * Add capabilities to administrator role
     */
    private static function add_capabilities() {
        $role = get_role( 'administrator' );
        if ( $role ) {
            $role->add_cap( 'seo_optimize' );
            $role->add_cap( 'seo_view_tests' );
        }

        $editor_role = get_role( 'editor' );
        if ( $editor_role ) {
            $editor_role->add_cap( 'seo_view_tests' );
        }
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        $defaults = [
            'gkso_daily_test_limit_per_user'        => 10,
            'gkso_enabled_post_types'               => [ 'post', 'page' ],
            'gkso_cooldown_days'                    => 30,
            'gkso_test_duration_days'               => 14,
            'gkso_enable_ip_allowlist'              => false,
            'gkso_n8n_ip_allowlist'                 => [],
            'gkso_n8n_webhook_url'                  => '',
            // Internal linking defaults
            'gkso_link_min_confidence'              => 62,
            'gkso_link_max_per_post'                => 6,
            'gkso_link_min_word_distance'           => 150,
            'gkso_link_weight_semantic'             => 35,
            'gkso_link_weight_keyword'              => 30,
            'gkso_link_weight_authority'            => 15,
            'gkso_link_weight_orphan'               => 10,
            'gkso_link_weight_recency'              => 10,
            'gkso_link_auto_approve'                => false,
            'gkso_link_auto_approve_threshold'      => 90,
            'gkso_link_avoid_headings'              => true,
            'gkso_link_avoid_first_para'            => true,
            'gkso_link_avoid_blockquotes'           => true,
            'gkso_link_prefer_early'                => true,
            'gkso_link_one_url_per_post'            => true,
            'gkso_link_instructions'                => '',
            'gkso_pillar_pages'                     => [],
        ];

        foreach ( $defaults as $option_name => $default_value ) {
            if ( false === get_option( $option_name ) ) {
                add_option( $option_name, $default_value );
            }
        }
    }

    /**
     * Generate initial shared secret
     */
    private static function generate_shared_secret() {
        $existing_secret = get_option( 'gkso_shared_secret' );
        if ( empty( $existing_secret ) ) {
            $secret = GKSO_Security::generate_secret( 64 );
            add_option( 'gkso_shared_secret', $secret );
        }
    }

    /**
     * Create database index for performance
     */
    private static function create_database_index() {
        global $wpdb;

        $index_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                   FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = %s
                    AND INDEX_NAME   = 'gkso_status'",
                $wpdb->postmeta
            )
        );

        if ( ! $index_exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query(
                "CREATE INDEX gkso_status ON `{$wpdb->postmeta}` (meta_key(32), meta_value(20))"
            );

            if ( $wpdb->last_error ) {
                gkso_log( 'DB index creation warning: ' . $wpdb->last_error, null, 'WARNING' );
            } else {
                gkso_log( 'DB index gkso_status created', null, 'INFO' );
            }
        }
    }

    /**
     * Upgrade routine for version changes
     *
     * @param string $old_version
     * @param string $new_version
     */
    public static function upgrade( $old_version, $new_version ) {
        do_action( 'gkso_upgrade', $old_version, $new_version );
    }
}