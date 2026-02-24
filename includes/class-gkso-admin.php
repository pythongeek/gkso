<?php
/**
 * GKSO_Admin class
 * 
 * Handles admin UI: meta boxes, settings page, dashboard widget, AJAX polling.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_Admin {
    
    /**
     * Initialize admin hooks
     */
    public function init() {
        // Admin hooks
        add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
        
        // Bulk actions
        add_action( 'admin_init', [ $this, 'register_bulk_actions' ] );
        
        // AJAX handlers
        add_action( 'wp_ajax_gkso_start_test', [ $this, 'ajax_start_test' ] );
        add_action( 'wp_ajax_gkso_early_terminate', [ $this, 'ajax_early_terminate' ] );
        add_action( 'wp_ajax_gkso_get_test_status', [ $this, 'ajax_get_test_status' ] );
        
        // Admin notices
        add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
    }
    
    /**
     * Register meta boxes
     */
    public function register_meta_boxes() {
        $enabled_post_types = get_option( 'gkso_enabled_post_types', [ 'post', 'page' ] );
        
        foreach ( $enabled_post_types as $post_type ) {
            add_meta_box(
                'gkso_seo_status',
                __( 'SEO A/B Test Status', 'gemini-kimi-seo' ),
                [ $this, 'render_meta_box' ],
                $post_type,
                'side',
                'high'
            );
        }
    }
    
    /**
     * Render meta box content
     * 
     * @param WP_Post $post The post object
     */
    public function render_meta_box( $post ) {
        include GKSO_PLUGIN_DIR . 'admin/views/meta-box.php';
    }
    
    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        // Add top-level dashboard page
        add_menu_page(
            __( 'SEO Optimizer', 'gemini-kimi-seo' ),
            __( 'SEO Optimizer', 'gemini-kimi-seo' ),
            'seo_view_tests',
            'gkso-dashboard',
            [ $this, 'render_dashboard_page' ],
            'dashicons-chart-line',
            80
        );
        
        // Add submenu pages
        add_submenu_page(
            'gkso-dashboard',
            __( 'Dashboard', 'gemini-kimi-seo' ),
            __( 'Dashboard', 'gemini-kimi-seo' ),
            'seo_view_tests',
            'gkso-dashboard'
        );
        
        add_submenu_page(
            'gkso-dashboard',
            __( 'Settings', 'gemini-kimi-seo' ),
            __( 'Settings', 'gemini-kimi-seo' ),
            'manage_options',
            'gkso-settings',
            [ $this, 'render_settings_page' ]
        );
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        ?>
        <div id="gkso-dashboard" 
             data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" 
             data-rest-url="<?php echo esc_url( rest_url() ); ?>">
        </div>
        <?php
    }
    
    /**
     * Get the parent menu slug based on installed SEO plugins
     * 
     * @return string Parent menu slug
     */
    private function get_seo_menu_parent() {
        // Check for Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) ) {
            return 'wpseo_dashboard';
        }
        
        // Check for Rank Math
        if ( class_exists( 'RankMath' ) ) {
            return 'rank-math';
        }
        
        // Check for AIOSEO
        if ( function_exists( 'aioseo' ) ) {
            return 'aioseo';
        }
        
        // Default to Tools menu
        return 'tools.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Save settings if submitted
        if ( isset( $_POST['gkso_save_settings'] ) && check_admin_referer( 'gkso_settings', 'gkso_settings_nonce' ) ) {
            $this->save_settings();
        }
        
        // Test webhook connection if requested
        if ( isset( $_POST['gkso_test_webhook'] ) && check_admin_referer( 'gkso_settings', 'gkso_settings_nonce' ) ) {
            $this->test_webhook_connection();
        }
        
        // Rotate secret if requested
        if ( isset( $_POST['gkso_rotate_secret'] ) && check_admin_referer( 'gkso_settings', 'gkso_settings_nonce' ) ) {
            $this->rotate_secret();
        }
        
        include GKSO_PLUGIN_DIR . 'admin/views/settings-page.php';
    }
    
    /**
     * Save settings
     */
    private function save_settings() {
        // n8n Webhook URL
        if ( isset( $_POST['gkso_n8n_webhook_url'] ) ) {
            update_option( 'gkso_n8n_webhook_url', esc_url_raw( wp_unslash( $_POST['gkso_n8n_webhook_url'] ) ) );
        }
        
        // Daily test limit
        if ( isset( $_POST['gkso_daily_test_limit'] ) ) {
            update_option( 'gkso_daily_test_limit_per_user', absint( $_POST['gkso_daily_test_limit'] ) );
        }
        
        // Test duration
        if ( isset( $_POST['gkso_test_duration'] ) ) {
            update_option( 'gkso_test_duration_days', absint( $_POST['gkso_test_duration'] ) );
        }
        
        // Cooldown days
        if ( isset( $_POST['gkso_cooldown_days'] ) ) {
            update_option( 'gkso_cooldown_days', absint( $_POST['gkso_cooldown_days'] ) );
        }
        
        // Enabled post types
        if ( isset( $_POST['gkso_enabled_post_types'] ) && is_array( $_POST['gkso_enabled_post_types'] ) ) {
            $post_types = array_map( 'sanitize_text_field', wp_unslash( $_POST['gkso_enabled_post_types'] ) );
            update_option( 'gkso_enabled_post_types', $post_types );
        }
        
        // IP allowlist
        if ( isset( $_POST['gkso_enable_ip_allowlist'] ) ) {
            update_option( 'gkso_enable_ip_allowlist', true );
        } else {
            update_option( 'gkso_enable_ip_allowlist', false );
        }
        
        if ( isset( $_POST['gkso_ip_allowlist'] ) ) {
            $ips = array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['gkso_ip_allowlist'] ) ) ) );
            $ips = array_filter( $ips );
            update_option( 'gkso_n8n_ip_allowlist', $ips );
        }
        
        add_settings_error(
            'gkso_settings',
            'gkso_settings_saved',
            __( 'Settings saved successfully.', 'gemini-kimi-seo' ),
            'success'
        );
    }
    
    /**
     * Test webhook connection
     */
    private function test_webhook_connection() {
        $result = GKSO_Webhook::test_connection();
        
        if ( is_wp_error( $result ) ) {
            add_settings_error(
                'gkso_settings',
                'gkso_webhook_test_failed',
                $result->get_error_message(),
                'error'
            );
        } else {
            add_settings_error(
                'gkso_settings',
                'gkso_webhook_test_success',
                $result['message'],
                $result['success'] ? 'success' : 'warning'
            );
        }
    }
    
    /**
     * Rotate shared secret
     */
    private function rotate_secret() {
        $new_secret = GKSO_Security::generate_secret( 64 );
        GKSO_Security::rotate_shared_secret( $new_secret );
        
        add_settings_error(
            'gkso_settings',
            'gkso_secret_rotated',
            __( 'Shared secret rotated successfully. Previous secret valid for 1 hour.', 'gemini-kimi-seo' ),
            'success'
        );
    }
    
    /**
     * Enqueue admin assets
     * 
     * @param string $hook The current admin page
     */
    public function enqueue_assets( $hook ) {
        $screen = get_current_screen();
        
        if ( ! $screen ) {
            return;
        }
        
        // Enqueue React Dashboard on dashboard page
        if ( $hook === 'toplevel_page_gkso-dashboard' ) {
            // Enqueue React dashboard
            wp_enqueue_style(
                'gkso-dashboard',
                GKSO_PLUGIN_URL . 'admin/assets/dashboard/main.css',
                [],
                GKSO_VERSION
            );
            
            wp_enqueue_script(
                'gkso-dashboard',
                GKSO_PLUGIN_URL . 'admin/assets/dashboard/main.js',
                [ 'wp-api-fetch' ],
                GKSO_VERSION,
                true
            );
            
            return;
        }

        // Internal Linking panel
        if ( strpos( $hook, 'gkso-internal-linking' ) !== false ) {
            wp_enqueue_script(
                'gkso-internal-linking',
                GKSO_PLUGIN_URL . 'admin/assets/js/gkso-internal-linking.js',
                [],
                GKSO_VERSION,
                true
            );
            return;
        }
        
        // Only enqueue on post edit screens, post list, and settings page
        $should_enqueue = false;
        
        if ( $screen->base === 'post' || $screen->base === 'post-new' ) {
            $should_enqueue = true;
        }
        
        if ( strpos( $screen->base, 'edit' ) !== false ) {
            $should_enqueue = true;
        }
        
        if ( strpos( $hook, 'gkso-settings' ) !== false ) {
            $should_enqueue = true;
        }
        
        if ( ! $should_enqueue ) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'gkso-admin',
            GKSO_PLUGIN_URL . 'admin/assets/css/gkso-admin.css',
            [],
            GKSO_VERSION
        );
        
        // Enqueue JS
        wp_enqueue_script(
            'gkso-admin',
            GKSO_PLUGIN_URL . 'admin/assets/js/gkso-admin.js',
            [ 'jquery', 'wp-api-fetch' ],
            GKSO_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script( 'gkso-admin', 'gksoAdmin', [
            'restUrl'       => rest_url( GKSO_REST_NAMESPACE ),
            'restNonce'     => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'ajaxNonce'     => wp_create_nonce( 'gkso_ajax_nonce' ),
            'strings'       => [
                'confirmStart'     => __( 'Start a new SEO A/B test for this post?', 'gemini-kimi-seo' ),
                'confirmTerminate' => __( 'Are you sure you want to stop this test? The test will be marked as failed.', 'gemini-kimi-seo' ),
                'starting'         => __( 'Starting test...', 'gemini-kimi-seo' ),
                'stopping'         => __( 'Stopping test...', 'gemini-kimi-seo' ),
                'error'            => __( 'An error occurred. Please try again.', 'gemini-kimi-seo' ),
            ],
        ] );
    }
    
    /**
     * Register dashboard widget
     */
    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'gkso_dashboard_widget',
            __( 'SEO A/B Test Overview', 'gemini-kimi-seo' ),
            [ $this, 'render_dashboard_widget' ]
        );
    }
    
    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        include GKSO_PLUGIN_DIR . 'admin/views/dashboard-widget.php';
    }
    
    /**
     * Register bulk actions
     */
    public function register_bulk_actions() {
        $enabled_post_types = get_option( 'gkso_enabled_post_types', [ 'post', 'page' ] );
        
        foreach ( $enabled_post_types as $post_type ) {
            add_filter( "bulk_actions-edit-{$post_type}", [ $this, 'add_bulk_action' ] );
            add_filter( "handle_bulk_actions-edit-{$post_type}", [ $this, 'handle_bulk_action' ], 10, 3 );
        }
    }
    
    /**
     * Add bulk action to dropdown
     * 
     * @param array $bulk_actions Current bulk actions
     * @return array Modified bulk actions
     */
    public function add_bulk_action( $bulk_actions ) {
        $bulk_actions['gkso_start_test'] = __( 'Start SEO Test', 'gemini-kimi-seo' );
        return $bulk_actions;
    }
    
    /**
     * Handle bulk action
     * 
     * @param string $redirect_to The redirect URL
     * @param string $doaction    The action being taken
     * @param array  $post_ids    Array of post IDs
     * @return string Modified redirect URL
     */
    public function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
        if ( $doaction !== 'gkso_start_test' ) {
            return $redirect_to;
        }
        
        $initiated = 0;
        $failed = 0;
        
        foreach ( $post_ids as $post_id ) {
            // Schedule single event for each post (to avoid timeout)
            wp_schedule_single_event( time(), 'gkso_bulk_initiate_test', [ $post_id ] );
            $initiated++;
        }
        
        $redirect_to = add_query_arg( [
            'gkso_bulk_initiated' => $initiated,
            'gkso_bulk_failed'    => $failed,
        ], $redirect_to );
        
        return $redirect_to;
    }
    
    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        if ( ! empty( $_GET['gkso_bulk_initiated'] ) ) {
            $count = intval( $_GET['gkso_bulk_initiated'] );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf(
                    /* translators: %d: Number of posts */
                    _n( '%d post queued for SEO test initiation.', '%d posts queued for SEO test initiation.', $count, 'gemini-kimi-seo' ),
                    $count
                ) )
            );
        }
        
        if ( ! empty( $_GET['gkso_bulk_failed'] ) ) {
            $count = intval( $_GET['gkso_bulk_failed'] );
            if ( $count > 0 ) {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html( sprintf(
                        /* translators: %d: Number of posts */
                        _n( '%d post failed to queue.', '%d posts failed to queue.', $count, 'gemini-kimi-seo' ),
                        $count
                    ) )
                );
            }
        }
    }
    
    /**
     * AJAX handler: Start test
     */
    public function ajax_start_test() {
        check_ajax_referer( 'gkso_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'seo_optimize' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'gemini-kimi-seo' ) ] );
        }
        
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'gemini-kimi-seo' ) ] );
        }
        
        // Use REST API internally
        $request = new WP_REST_Request( 'POST', '/' . GKSO_REST_NAMESPACE . '/initiate-test' );
        $request->set_param( 'post_id', $post_id );
        
        $response = rest_do_request( $request );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }
        
        if ( $response->is_error() ) {
            $error = $response->as_error();
            wp_send_json_error( [ 'message' => $error->get_error_message() ] );
        }
        
        wp_send_json_success( $response->get_data() );
    }
    
    /**
     * AJAX handler: Early terminate
     */
    public function ajax_early_terminate() {
        check_ajax_referer( 'gkso_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'seo_optimize' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'gemini-kimi-seo' ) ] );
        }
        
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : __( 'Manually terminated by user', 'gemini-kimi-seo' );
        
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'gemini-kimi-seo' ) ] );
        }
        
        // Use REST API internally
        $request = new WP_REST_Request( 'POST', '/' . GKSO_REST_NAMESPACE . '/early-terminate' );
        $request->set_param( 'post_id', $post_id );
        $request->set_param( 'reason', $reason );
        
        $response = rest_do_request( $request );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }
        
        if ( $response->is_error() ) {
            $error = $response->as_error();
            wp_send_json_error( [ 'message' => $error->get_error_message() ] );
        }
        
        wp_send_json_success( $response->get_data() );
    }
    
    /**
     * AJAX handler: Get test status
     */
    public function ajax_get_test_status() {
        check_ajax_referer( 'gkso_ajax_nonce', 'nonce' );
        
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'gemini-kimi-seo' ) ] );
        }
        
        if ( ! current_user_can( 'edit_post', $post_id ) && ! current_user_can( 'seo_view_tests' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'gemini-kimi-seo' ) ] );
        }
        
        // Use REST API internally
        $request = new WP_REST_Request( 'GET', '/' . GKSO_REST_NAMESPACE . '/test-status/' . $post_id );
        
        $response = rest_do_request( $request );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }
        
        if ( $response->is_error() ) {
            $error = $response->as_error();
            wp_send_json_error( [ 'message' => $error->get_error_message() ] );
        }
        
        wp_send_json_success( $response->get_data() );
    }
}

// Hook for bulk test initiation
add_action( 'gkso_bulk_initiate_test', function( $post_id ) {
    // This runs via WP Cron, so we need to use the REST API internally
    $request = new WP_REST_Request( 'POST', '/' . GKSO_REST_NAMESPACE . '/initiate-test' );
    $request->set_param( 'post_id', $post_id );
    
    // Set current user to admin for capability checks
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
    if ( ! empty( $admins ) ) {
        wp_set_current_user( $admins[0]->ID );
    }
    
    rest_do_request( $request );
} );
