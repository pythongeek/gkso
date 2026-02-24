<?php
/**
 * GKSO_Rest_Controller class
 * 
 * Registers and handles all REST API endpoints for the SEO optimizer.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_Rest_Controller {
    
    /**
     * Initialize REST API routes
     */
    public function init() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }
    
    /**
     * Register all REST API routes
     */
    public function register_routes() {
        $namespace = GKSO_REST_NAMESPACE;
        
        // Endpoint 1: POST /initiate-test
        register_rest_route( $namespace, '/initiate-test', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'initiate_test' ],
                'permission_callback' => [ $this, 'check_seo_optimize_permission' ],
                'args'                => [
                    'post_id'         => [
                        'required'          => true,
                        'type'              => 'integer',
                        'validate_callback' => [ $this, 'validate_post_id' ],
                        'sanitize_callback' => 'absint',
                    ],
                    'manual_override' => [
                        'required'   => false,
                        'type'       => 'boolean',
                        'default'    => false,
                    ],
                    'priority'        => [
                        'required'   => false,
                        'type'       => 'string',
                        'enum'       => [ 'normal', 'high' ],
                        'default'    => 'normal',
                    ],
                    'preferred_model' => [
                        'required'   => false,
                        'type'       => 'string',
                        'enum'       => [ 'gemini', 'kimi', 'auto' ],
                        'default'    => 'auto',
                    ],
                ],
            ],
        ] );
        
        // Endpoint 2: POST /update-meta
        register_rest_route( $namespace, '/update-meta', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'update_meta' ],
                'permission_callback' => [ $this, 'check_n8n_permission' ],
                'args'                => [
                    'post_id'          => [
                        'required'          => true,
                        'type'              => 'integer',
                        'validate_callback' => [ $this, 'validate_post_id' ],
                    ],
                    'decision'         => [
                        'required' => true,
                        'type'     => 'string',
                        'enum'     => [ 'test_wins', 'baseline_wins', 'initiate_test' ],
                    ],
                    'final_title'      => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'final_description' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'test_metrics'     => [
                        'required' => true,
                        'type'     => 'object',
                    ],
                    'baseline_metrics' => [
                        'required' => true,
                        'type'     => 'object',
                    ],
                    'improvement'      => [
                        'required' => true,
                        'type'     => 'object',
                    ],
                ],
            ],
        ] );
        
        // Endpoint 3: GET /test-status/{post_id}
        register_rest_route( $namespace, '/test-status/(?P<post_id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_test_status' ],
                'permission_callback' => [ $this, 'check_test_status_permission' ],
                'args'                => [
                    'post_id' => [
                        'validate_callback' => [ $this, 'validate_post_id' ],
                    ],
                ],
            ],
        ] );
        
        // Endpoint 4: POST /early-terminate
        register_rest_route( $namespace, '/early-terminate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'early_terminate' ],
                'permission_callback' => [ $this, 'check_seo_optimize_permission' ],
                'args'                => [
                    'post_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'validate_callback' => [ $this, 'validate_post_id' ],
                    ],
                    'reason'  => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ],
        ] );
        
        // Endpoint 5: POST /update-baseline (for baseline metric updates)
        register_rest_route( $namespace, '/update-baseline', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'update_baseline' ],
                'permission_callback' => [ $this, 'check_n8n_permission' ],
                'args'                => [
                    'post_id'                   => [
                        'required'          => true,
                        'type'              => 'integer',
                        'validate_callback' => [ $this, 'validate_post_id' ],
                    ],
                    'baseline_ctr'              => [
                        'required' => false,
                        'type'     => 'number',
                    ],
                    'baseline_position'         => [
                        'required' => false,
                        'type'     => 'number',
                    ],
                    'baseline_pageviews'        => [
                        'required' => false,
                        'type'     => 'integer',
                    ],
                    'baseline_impressions'      => [
                        'required' => false,
                        'type'     => 'integer',
                    ],
                    'baseline_date_range'       => [
                        'required' => false,
                        'type'     => 'object',
                    ],
                ],
            ],
        ] );
        
        // Additional endpoint: POST /update-snapshot (for mid-test updates)
        register_rest_route( $namespace, '/update-snapshot', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'update_snapshot' ],
                'permission_callback' => [ $this, 'check_n8n_permission' ],
                'args'                => [
                    'post_id'  => [
                        'required'          => true,
                        'type'              => 'integer',
                        'validate_callback' => [ $this, 'validate_post_id' ],
                    ],
                    'snapshot' => [
                        'required' => true,
                        'type'     => 'object',
                    ],
                ],
            ],
        ] );
        
        // Dashboard Endpoints
        
        // GET /dashboard/overview - Aggregate stats
        register_rest_route( $namespace, '/dashboard/overview', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_dashboard_overview' ],
                'permission_callback' => [ $this, 'check_dashboard_permission' ],
            ],
        ] );
        
        // GET /dashboard/posts - Paginated post list
        register_rest_route( $namespace, '/dashboard/posts', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_dashboard_posts' ],
                'permission_callback' => [ $this, 'check_dashboard_permission' ],
                'args'                => [
                    'status'    => [
                        'required'   => false,
                        'type'       => 'string',
                        'enum'       => [ 'Baseline', 'Testing', 'Optimized', 'Failed', 'all' ],
                        'default'    => 'all',
                    ],
                    'per_page'  => [
                        'required'   => false,
                        'type'       => 'integer',
                        'default'    => 20,
                        'minimum'    => 1,
                        'maximum'    => 100,
                    ],
                    'page'      => [
                        'required'   => false,
                        'type'       => 'integer',
                        'default'    => 1,
                        'minimum'    => 1,
                    ],
                    'orderby'   => [
                        'required'   => false,
                        'type'       => 'string',
                        'enum'       => [ 'health_score', 'last_test', 'title' ],
                        'default'    => 'title',
                    ],
                ],
            ],
        ] );
        
        // GET /dashboard/settings - Get settings
        register_rest_route( $namespace, '/dashboard/settings', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_dashboard_settings' ],
                'permission_callback' => [ $this, 'check_dashboard_permission' ],
            ],
        ] );
        
        // POST /dashboard/settings - Save settings
        register_rest_route( $namespace, '/dashboard/settings', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'save_dashboard_settings' ],
                'permission_callback' => [ $this, 'check_manage_options_permission' ],
            ],
        ] );
        
        // POST /dashboard/test-webhook - Test webhook connection
        register_rest_route( $namespace, '/dashboard/test-webhook', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'test_webhook' ],
                'permission_callback' => [ $this, 'check_manage_options_permission' ],
            ],
        ] );
        
        // POST /dashboard/rotate-secret - Rotate shared secret
        register_rest_route( $namespace, '/dashboard/rotate-secret', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'rotate_secret' ],
                'permission_callback' => [ $this, 'check_manage_options_permission' ],
            ],
        ] );
    }
    
    /**
     * Validate post ID
     * 
     * @param mixed $param The parameter value
     * @return true|WP_Error True if valid, WP_Error otherwise
     */
    public function validate_post_id( $param ) {
        $post_id = intval( $param );
        $post = get_post( $post_id );
        
        if ( ! $post ) {
            return new WP_Error(
                'invalid_post_id',
                __( 'Invalid post ID.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        return true;
    }
    
    /**
     * Check seo_optimize permission
     * 
     * @return true|WP_Error True if allowed, WP_Error otherwise
     */
    public function check_seo_optimize_permission() {
        return GKSO_Security::check_capability( 'seo_optimize' );
    }
    
    /**
     * Check test status view permission
     * 
     * @param WP_REST_Request $request The request
     * @return true|WP_Error True if allowed, WP_Error otherwise
     */
    public function check_test_status_permission( WP_REST_Request $request ) {
        $post_id = intval( $request['post_id'] );
        
        // Check if user can edit the post or has view permission
        if ( current_user_can( 'edit_post', $post_id ) || current_user_can( 'seo_view_tests' ) ) {
            return true;
        }
        
        return new WP_Error(
            'rest_forbidden',
            __( 'You do not have permission to view this test status.', 'gemini-kimi-seo' ),
            [ 'status' => 403 ]
        );
    }
    
    /**
     * Check dashboard view permission
     * 
     * @return true|WP_Error True if allowed, WP_Error otherwise
     */
    public function check_dashboard_permission() {
        return GKSO_Security::check_capability( 'seo_view_tests' );
    }
    
    /**
     * Check manage_options permission for settings
     * 
     * @return true|WP_Error True if allowed, WP_Error otherwise
     */
    public function check_manage_options_permission() {
        return GKSO_Security::check_capability( 'manage_options' );
    }
    
    /**
     * Check n8n permission (signature + IP)
     * 
     * @param WP_REST_Request $request The request
     * @return true|WP_Error True if allowed, WP_Error otherwise
     */
    public function check_n8n_permission( WP_REST_Request $request ) {
        // Check IP allowlist first
        $ip_check = GKSO_Security::check_ip_allowlist();
        
        if ( is_wp_error( $ip_check ) ) {
            return $ip_check;
        }
        
        // Verify signature
        return GKSO_Security::verify_n8n_signature( $request );
    }
    
    /**
     * Handle initiate-test endpoint
     * 
     * @param WP_REST_Request $request The request
     * @return WP_REST_Response|WP_Error The response
     */
    public function initiate_test( WP_REST_Request $request ) {
        $post_id = intval( $request['post_id'] );
        $manual_override = (bool) $request['manual_override'];
        $priority = sanitize_text_field( $request['priority'] );
        $preferred_model = sanitize_text_field( $request['preferred_model'] );
        
        // Verify post is published
        $post = get_post( $post_id );
        if ( $post->post_status !== 'publish' ) {
            return new WP_Error(
                'post_not_published',
                __( 'Post must be published to start an SEO test.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        // Check user rate limit
        $user_id = get_current_user_id();
        $rate_limit = GKSO_Security::check_user_rate_limit( $user_id );
        
        if ( is_wp_error( $rate_limit ) ) {
            return $rate_limit;
        }
        
        // Check if test can be initiated
        $can_initiate = GKSO_State_Machine::can_initiate_test( $post_id, $manual_override );
        
        if ( is_wp_error( $can_initiate ) ) {
            return $can_initiate;
        }
        
        // Dispatch webhook to n8n
        $webhook_params = [
            'manual_override' => $manual_override,
            'priority'        => $priority,
            'preferred_model' => $preferred_model,
        ];
        
        $webhook_response = GKSO_Webhook::dispatch_to_n8n( $post_id, $webhook_params );
        
        if ( is_wp_error( $webhook_response ) ) {
            return new WP_Error(
                'webhook_failed',
                $webhook_response->get_error_message(),
                [ 'status' => 502 ]
            );
        }
        
        // Get test ID from webhook response
        $test_id = ! empty( $webhook_response['test_id'] ) ? $webhook_response['test_id'] : GKSO_State_Machine::generate_uuid();
        
        // Transition to testing state
        $transition_params = [
            'ai_model' => $preferred_model,
        ];
        
        $transition = GKSO_State_Machine::transition_to_testing( $post_id, $test_id, $transition_params );
        
        if ( is_wp_error( $transition ) ) {
            return $transition;
        }
        
        // Increment rate limit counter
        GKSO_Security::increment_rate_limit( $user_id );
        
        // Calculate estimated completion
        $test_duration = intval( get_option( 'gkso_test_duration_days', 14 ) );
        $estimated_completion = date( 'c', strtotime( "+{$test_duration} days" ) );
        
        // Return 202 Accepted response
        $response_data = [
            'success'              => true,
            'test_id'              => $test_id,
            'post_id'              => $post_id,
            'status'               => 'Testing',
            'estimated_completion' => $estimated_completion,
            'status_url'           => rest_url( GKSO_REST_NAMESPACE . '/test-status/' . $post_id ),
            'message'              => __( 'SEO test initiated successfully.', 'gemini-kimi-seo' ),
        ];
        
        return new WP_REST_Response( $response_data, 202 );
    }
    
    /**
     * Handle update-meta endpoint (n8n callback)
     * 
     * @param WP_REST_Request $request The request
     * @return WP_REST_Response|WP_Error The response
     */
    public function update_meta( WP_REST_Request $request ) {
        $post_id = intval( $request['post_id'] );
        $decision = sanitize_text_field( $request['decision'] );
        $final_title = sanitize_text_field( $request['final_title'] );
        $final_description = sanitize_textarea_field( $request['final_description'] );
        $test_metrics = $request['test_metrics'];
        $baseline_metrics = $request['baseline_metrics'];
        $improvement = $request['improvement'];
        
        // Handle initiate_test decision (from n8n after AI generation)
        if ( $decision === 'initiate_test' ) {
            return $this->handle_initiate_test_callback( $request );
        }
        
        // Build metrics array
        $metrics = [
            'test'     => $test_metrics,
            'baseline' => $baseline_metrics,
            'improvement' => $improvement,
        ];
        
        // Process based on decision
        if ( $decision === 'test_wins' ) {
            $result = GKSO_State_Machine::transition_to_optimized( $post_id, $final_title, $final_description, $metrics );
        } else {
            $result = GKSO_State_Machine::transition_to_baseline( $post_id, $metrics );
        }
        
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        
        $response_data = [
            'success'   => true,
            'post_id'   => $post_id,
            'decision'  => $decision,
            'new_status' => $decision === 'test_wins' ? 'Optimized' : 'Baseline',
            'message'   => __( 'Test completed successfully.', 'gemini-kimi-seo' ),
        ];
        
        return new WP_REST_Response( $response_data, 200 );
    }
    
    /**
     * Handle initiate_test callback from n8n
     * 
     * @param WP_REST_Request $request The request
     * @return WP_REST_Response|WP_Error The response
     */
    private function handle_initiate_test_callback( WP_REST_Request $request ) {
        $post_id = intval( $request['post_id'] );
        $test_title = sanitize_text_field( $request['test_title'] );
        $test_description = sanitize_textarea_field( $request['test_description'] );
        $ai_model = sanitize_text_field( $request['ai_model'] );
        $prompt_hash = sanitize_text_field( $request['generation_prompt_hash'] );
        $baseline_metrics = $request['baseline_metrics'];
        $test_id = sanitize_text_field( $request['test_id'] );
        
        // Verify post is in Testing state
        $current_status = GKSO_State_Machine::get_status( $post_id );
        
        if ( $current_status !== 'Testing' ) {
            // Transition to testing with the AI-generated content
            $transition_params = [
                'test_title'    => $test_title,
                'test_description' => $test_description,
                'ai_model'      => $ai_model,
                'prompt_hash'   => $prompt_hash,
            ];
            
            $transition = GKSO_State_Machine::transition_to_testing( $post_id, $test_id, $transition_params );
            
            if ( is_wp_error( $transition ) ) {
                return $transition;
            }
        }
        
        // Store baseline metrics
        if ( ! empty( $baseline_metrics ) ) {
            GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::BASELINE_CTR, $baseline_metrics['ctr'] ?? 0 );
            GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::BASELINE_POSITION, $baseline_metrics['position'] ?? 0 );
            GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::BASELINE_PAGEVIEWS, $baseline_metrics['pageviews'] ?? 0 );
            GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::BASELINE_IMPRESSIONS, $baseline_metrics['impressions'] ?? 0 );
            
            if ( ! empty( $baseline_metrics['date_range'] ) ) {
                $date_range = is_array( $baseline_metrics['date_range'] ) 
                    ? $baseline_metrics['date_range']['start'] . ' to ' . $baseline_metrics['date_range']['end']
                    : $baseline_metrics['date_range'];
                GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::BASELINE_DATE_RANGE, $date_range );
            }
        }
        
        // Apply the test title/description to the post (but don't make it permanent yet)
        // This is done via a temporary meta that the SEO plugins can read
        update_post_meta( $post_id, '_seo_test_title_active', $test_title );
        update_post_meta( $post_id, '_seo_test_description_active', $test_description );
        
        $response_data = [
            'success'     => true,
            'post_id'     => $post_id,
            'test_id'     => $test_id,
            'status'      => 'Testing',
            'test_title'  => $test_title,
            'ai_model'    => $ai_model,
            'message'     => __( 'Test initiated with AI-generated content.', 'gemini-kimi-seo' ),
        ];
        
        return new WP_REST_Response( $response_data, 200 );
    }
    
    /**
     * Handle update-baseline endpoint (n8n callback for baseline updates)
     * 
     * @param WP_REST_Request $request The request
     * @return WP_REST_Response|WP_Error The response
     */
    public function update_baseline( WP_REST_Request $request ) {
        $post_id = intval( $request['post_id'] );
        $baseline_ctr = floatval( $request['baseline_ctr'] ?? 0 );
        $baseline_position = floatval( $request['baseline_position'] ?? 0 );
        $baseline_pageviews = intval( $request['baseline_pageviews'] ?? 0 );
        $baseline_impressions = intval( $request['baseline_impressions'] ?? 0 );
        $baseline_date_range = $request['baseline_date_range'];
        
        // Store baseline metrics
        GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::BASELINE_CTR, $baseline_ctr );
        GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::BASELINE_POSITION, $baseline_position );
        GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::BASELINE_PAGEVIEWS, $baseline_pageviews );
        GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::BASELINE_IMPRESSIONS, $baseline_impressions );
        
        if ( ! empty( $baseline_date_range ) ) {
            $date_range_str = is_array( $baseline_date_range ) 
                ? $baseline_date_range['start'] . ' to ' . $baseline_date_range['end']
                : sanitize_text_field( $baseline_date_range );
            GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::BASELINE_DATE_RANGE, $date_range_str );
        }
        
        $response_data = [
            'success' => true,
            'post_id' => $post_id,
            'message' => __( 'Baseline metrics updated successfully.', 'gemini-kimi-seo' ),
            'baseline' => [
                'ctr'         => $baseline_ctr,
                'position'    => $baseline_position,
                'pageviews'   => $baseline_pageviews,
                'impressions' => $baseline_impressions,
            ],
        ];
        
        return new WP_REST_Response( $response_data, 200 );
    }
    
    /**
     * Handle get test-status endpoint
     * 
     * @param WP_REST_Request $request The request
     * @return WP_REST_Response|WP_Error The response
     */
    public function get_test_status( WP_REST_Request $request ) {
        $post_id = intval( $request['post_id'] );
        
        $status = GKSO_State_Machine::get_status( $post_id );
        $version = intval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::VERSION, 0 ) );
        $history = GKSO_Meta_Schema::get_history( $post_id );
        
        $response_data = [
            'post_id'       => $post_id,
            'status'        => $status,
            'version'       => $version,
            'history_count' => count( $history ),
        ];
        
        // Add Testing-specific data
        if ( $status === 'Testing' ) {
            $started = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::STARTED );
            $test_title = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_TITLE );
            $ai_model = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_AI_MODEL );
            $snapshots = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_SNAPSHOTS, [] );
            
            if ( ! empty( $started ) ) {
                $started_timestamp = strtotime( $started );
                $elapsed_seconds = time() - $started_timestamp;
                $elapsed_days = floor( $elapsed_seconds / DAY_IN_SECONDS );
                
                $test_duration = intval( get_option( 'gkso_test_duration_days', 14 ) );
                $progress_percent = min( 100, round( ( $elapsed_days / $test_duration ) * 100, 2 ) );
                
                $estimated_completion = date( 'c', strtotime( "+{$test_duration} days", $started_timestamp ) );
                
                $response_data['started'] = $started;
                $response_data['elapsed_days'] = $elapsed_days;
                $response_data['progress_percent'] = $progress_percent;
                $response_data['estimated_completion'] = $estimated_completion;
            }
            
            $response_data['test_title'] = $test_title;
            $response_data['ai_model'] = $ai_model;
            $response_data['latest_snapshot'] = ! empty( $snapshots ) ? end( $snapshots ) : null;
        }
        
        // Add Optimized or Failed data
        if ( in_array( $status, [ 'Optimized', 'Failed' ], true ) && ! empty( $history ) ) {
            $response_data['last_test'] = end( $history );
        }
        
        // Add history (last 5 records)
        $response_data['recent_history'] = array_slice( $history, -5 );
        
        return new WP_REST_Response( $response_data, 200 );
    }
    
    /**
     * Handle early-terminate endpoint
     * 
     * @param WP_REST_Request $request The request
     * @return WP_REST_Response|WP_Error The response
     */
    public function early_terminate( WP_REST_Request $request ) {
        $post_id = intval( $request['post_id'] );
        $reason = sanitize_textarea_field( $request['reason'] );
        
        // Verify status is Testing
        $current_status = GKSO_State_Machine::get_status( $post_id );
        
        if ( $current_status !== 'Testing' ) {
            return new WP_Error(
                'not_testing',
                __( 'Cannot terminate: post is not in Testing state.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        // Transition to failed
        $result = GKSO_State_Machine::transition_to_failed( $post_id, $reason );
        
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        
        $response_data = [
            'success' => true,
            'post_id' => $post_id,
            'new_status' => 'Failed',
            'reason' => $reason,
            'message' => __( 'Test terminated and reverted.', 'gemini-kimi-seo' ),
        ];
        
        return new WP_REST_Response( $response_data, 200 );
    }
    
    /**
     * Handle update-snapshot endpoint (mid-test updates)
     * 
     * @param WP_REST_Request $request The request
     * @return WP_REST_Response|WP_Error The response
     */
    public function update_snapshot( WP_REST_Request $request ) {
        $post_id = intval( $request['post_id'] );
        $snapshot = $request['snapshot'];
        
        // Verify status is Testing
        $current_status = GKSO_State_Machine::get_status( $post_id );
        
        if ( $current_status !== 'Testing' ) {
            return new WP_Error(
                'not_testing',
                __( 'Cannot update snapshot: post is not in Testing state.', 'gemini-kimi-seo' ),
                [ 'status' => 400 ]
            );
        }
        
        // Get existing snapshots
        $snapshots = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_SNAPSHOTS, [] );
        
        if ( ! is_array( $snapshots ) ) {
            $snapshots = [];
        }
        
        // Add timestamp to snapshot
        $snapshot['timestamp'] = current_time( 'c' );
        
        // Append new snapshot
        $snapshots[] = $snapshot;
        
        // Keep only last 20 snapshots
        if ( count( $snapshots ) > 20 ) {
            $snapshots = array_slice( $snapshots, -20 );
        }
        
        // Save snapshots
        GKSO_Meta_Schema::update_meta( $post_id, GKSO_Meta_Schema::TEST_SNAPSHOTS, $snapshots );
        
        $response_data = [
            'success'         => true,
            'post_id'         => $post_id,
            'snapshot_count'  => count( $snapshots ),
            'message'         => __( 'Snapshot updated successfully.', 'gemini-kimi-seo' ),
        ];
        
        return new WP_REST_Response( $response_data, 200 );
    }
    
    /**
     * Handle dashboard overview endpoint
     * 
     * @return WP_REST_Response The response
     */
    public function get_dashboard_overview() {
        global $wpdb;
        
        // Check cache
        $cached = get_transient( 'gkso_overview_cache' );
        if ( $cached ) {
            return new WP_REST_Response( $cached, 200 );
        }
        
        $enabled_post_types = get_option( 'gkso_enabled_post_types', [ 'post', 'page' ] );
        
        // Count by status
        $status_counts = [
            'Baseline'   => 0,
            'Testing'    => 0,
            'Optimized'  => 0,
            'Failed'     => 0,
        ];
        
        foreach ( $status_counts as $status => &$count ) {
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm 
                 JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                 WHERE pm.meta_key = %s 
                 AND pm.meta_value = %s 
                 AND p.post_type IN ('" . implode( "','", array_map( 'esc_sql', $enabled_post_types ) ) . "')
                 AND p.post_status = 'publish'",
                GKSO_Meta_Schema::STATUS,
                $status
            ) );
        }
        
        // Calculate success rate from history
        $total_tests = 0;
        $successful_tests = 0;
        $total_ctr_improvement = 0;
        $total_position_improvement = 0;
        $ctr_trend = [];
        
        // Get all posts with history
        $posts_with_history = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
             WHERE meta_key = %s 
             AND meta_value != ''",
            GKSO_Meta_Schema::TEST_HISTORY
        ) );
        
        foreach ( $posts_with_history as $row ) {
            $history = json_decode( $row->meta_value, true );
            if ( ! is_array( $history ) ) continue;
            
            foreach ( $history as $record ) {
                if ( empty( $record['completed_at'] ) ) continue;
                $completed = strtotime( $record['completed_at'] );
                
                // Only count tests from last 30 days for trend
                if ( $completed > strtotime( '-30 days' ) ) {
                    $total_tests++;
                    
                    if ( $record['result'] === 'optimized' ) {
                        $successful_tests++;
                    }
                    
                    if ( ! empty( $record['metrics']['improvement']['ctr'] ) ) {
                        $ctr_improvement = $record['metrics']['improvement']['ctr'] * 100;
                        $total_ctr_improvement += $ctr_improvement;
                        
                        $ctr_trend[] = [
                            'date'            => date( 'Y-m-d', $completed ),
                            'ctr_improvement' => round( $ctr_improvement, 2 ),
                        ];
                    }
                    
                    if ( ! empty( $record['metrics']['improvement']['position'] ) ) {
                        $total_position_improvement += $record['metrics']['improvement']['position'];
                    }
                }
            }
        }
        
        // Sort CTR trend by date
        usort( $ctr_trend, function( $a, $b ) {
            return strcmp( $a['date'], $b['date'] );
        });
        
        $response_data = [
            'total_posts_monitored'    => array_sum( $status_counts ),
            'active_tests_count'       => $status_counts['Testing'],
            'optimized_count'          => $status_counts['Optimized'],
            'failed_count'             => $status_counts['Failed'],
            'success_rate_pct'         => $total_tests > 0 ? round( ( $successful_tests / $total_tests ) * 100, 1 ) : 0,
            'avg_ctr_improvement_pct'  => $total_tests > 0 ? round( $total_ctr_improvement / $total_tests, 1 ) : 0,
            'avg_position_improvement' => $total_tests > 0 ? round( $total_position_improvement / $total_tests, 1 ) : 0,
            'tests_run_last_30_days'   => $total_tests,
            'ctr_trend'                => $ctr_trend,
        ];
        
        // Cache for 5 minutes
        set_transient( 'gkso_overview_cache', $response_data, 5 * MINUTE_IN_SECONDS );
        
        return new WP_REST_Response( $response_data, 200 );
    }
    
    /**
     * Handle dashboard posts endpoint
     * 
     * @param WP_REST_Request $request The request
     * @return WP_REST_Response The response
     */
    public function get_dashboard_posts( WP_REST_Request $request ) {
        $status = sanitize_text_field( $request['status'] );
        $per_page = intval( $request['per_page'] );
        $page = intval( $request['page'] );
        $orderby = sanitize_text_field( $request['orderby'] );
        
        $enabled_post_types = get_option( 'gkso_enabled_post_types', [ 'post', 'page' ] );
        
        $meta_query = [];
        if ( $status !== 'all' ) {
            $meta_query[] = [
                'key'   => GKSO_Meta_Schema::STATUS,
                'value' => $status,
            ];
        }
        
        $order = 'ASC';
        $orderby_field = 'title';
        
        switch ( $orderby ) {
            case 'last_test':
                $orderby_field = 'meta_value';
                $meta_query[] = [
                    'key'     => GKSO_Meta_Schema::STARTED,
                    'compare' => 'EXISTS',
                ];
                $order = 'DESC';
                break;
            case 'health_score':
                // Custom ordering would require additional meta
                $orderby_field = 'title';
                break;
        }
        
        $query_args = [
            'post_type'      => $enabled_post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => $orderby_field,
            'order'          => $order,
            'meta_query'     => $meta_query,
        ];
        
        $query = new WP_Query( $query_args );
        
        $posts = [];
        foreach ( $query->posts as $post ) {
            $post_id = $post->ID;
            $status = GKSO_State_Machine::get_status( $post_id );
            $history = GKSO_Meta_Schema::get_history( $post_id );
            $last_test = ! empty( $history ) ? end( $history ) : null;
            
            // Calculate health score (simplified)
            $baseline_ctr = floatval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::BASELINE_CTR, 0 ) );
            $baseline_position = floatval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::BASELINE_POSITION, 20 ) );
            $health_score = min( 100, max( 0, 100 - ( $baseline_position * 3 ) + ( $baseline_ctr * 100 ) ) );
            
            $posts[] = [
                'post_id'             => $post_id,
                'post_title'          => $post->post_title,
                'post_url'            => get_permalink( $post_id ),
                'post_type'           => $post->post_type,
                'status'              => $status,
                'version'             => intval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::VERSION, 0 ) ),
                'health_score'        => round( $health_score ),
                'last_test_date'      => $last_test ? ( $last_test['completed_at'] ?? null ) : null,
                'last_ctr_improvement'=> $last_test && ! empty( $last_test['metrics']['improvement']['ctr'] ) 
                    ? $last_test['metrics']['improvement']['ctr'] * 100 
                    : null,
                'current_position'    => floatval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::BASELINE_POSITION, 0 ) ),
            ];
        }
        
        $response_data = [
            'posts'       => $posts,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'page'        => $page,
        ];
        
        return new WP_REST_Response( $response_data, 200 );
    }
    
    /**
     * Handle dashboard settings GET endpoint
     * 
     * @return WP_REST_Response The response
     */
    public function get_dashboard_settings() {
        $settings = [
            'n8n_webhook_url'       => get_option( 'gkso_n8n_webhook_url', '' ),
            'test_duration_days'    => intval( get_option( 'gkso_test_duration_days', 14 ) ),
            'cooldown_days'         => intval( get_option( 'gkso_cooldown_days', 30 ) ),
            'daily_test_limit'      => intval( get_option( 'gkso_daily_test_limit_per_user', 10 ) ),
            'enable_ip_allowlist'   => (bool) get_option( 'gkso_enable_ip_allowlist', false ),
            'allowed_ips'           => get_option( 'gkso_n8n_ip_allowlist', [] ),
            'enabled_post_types'    => get_option( 'gkso_enabled_post_types', [ 'post', 'page' ] ),
        ];
        
        return new WP_REST_Response( $settings, 200 );
    }
    
    /**
     * Handle dashboard settings POST endpoint
     * 
     * @param WP_REST_Request $request The request
     * @return WP_REST_Response The response
     */
    public function save_dashboard_settings( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        
        // Sanitize and save each setting
        if ( isset( $params['n8n_webhook_url'] ) ) {
            update_option( 'gkso_n8n_webhook_url', esc_url_raw( $params['n8n_webhook_url'] ) );
        }
        
        if ( isset( $params['test_duration_days'] ) ) {
            update_option( 'gkso_test_duration_days', absint( $params['test_duration_days'] ) );
        }
        
        if ( isset( $params['cooldown_days'] ) ) {
            update_option( 'gkso_cooldown_days', absint( $params['cooldown_days'] ) );
        }
        
        if ( isset( $params['daily_test_limit'] ) ) {
            update_option( 'gkso_daily_test_limit_per_user', absint( $params['daily_test_limit'] ) );
        }
        
        if ( isset( $params['enable_ip_allowlist'] ) ) {
            update_option( 'gkso_enable_ip_allowlist', (bool) $params['enable_ip_allowlist'] );
        }
        
        if ( isset( $params['allowed_ips'] ) && is_array( $params['allowed_ips'] ) ) {
            $ips = array_map( 'sanitize_text_field', $params['allowed_ips'] );
            $ips = array_filter( $ips );
            update_option( 'gkso_n8n_ip_allowlist', $ips );
        }
        
        if ( isset( $params['enabled_post_types'] ) && is_array( $params['enabled_post_types'] ) ) {
            $post_types = array_map( 'sanitize_text_field', $params['enabled_post_types'] );
            update_option( 'gkso_enabled_post_types', $post_types );
        }
        
        // Clear overview cache when settings change
        delete_transient( 'gkso_overview_cache' );
        
        return new WP_REST_Response( [ 'success' => true, 'message' => __( 'Settings saved.', 'gemini-kimi-seo' ) ], 200 );
    }
    
    /**
     * Handle test webhook endpoint
     * 
     * @return WP_REST_Response The response
     */
    public function test_webhook() {
        $result = GKSO_Webhook::test_connection();
        
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 502 );
        }
        
        return new WP_REST_Response( $result, 200 );
    }
    
    /**
     * Handle rotate secret endpoint
     * 
     * @return WP_REST_Response The response
     */
    public function rotate_secret() {
        $new_secret = GKSO_Security::generate_secret( 64 );
        GKSO_Security::rotate_shared_secret( $new_secret );
        
        return new WP_REST_Response( [
            'success'    => true,
            'new_secret' => $new_secret,
            'message'    => __( 'Secret rotated. Update n8n credential within 1 hour.', 'gemini-kimi-seo' ),
        ], 200 );
    }
}
