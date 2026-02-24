<?php
/**
 * GKSO_Internal_Linking class
 *
 * Implements the 6-phase Internal Linking Agent.
 *
 * @package Gemini_Kimi_SEO
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class GKSO_Internal_Linking
 * 
 * REST routes exposed:
 *   GET  /link-index-stats
 *   GET  /link-algorithm-settings
 *   POST /link-algorithm-settings
 *   GET  /link-instructions
 *   POST /link-instructions
 *   GET  /link-pillar-pages
 *   POST /link-pillar-pages
 *   POST /rebuild-link-index
 *   POST /run-link-analysis
 *   GET  /link-suggestions/{post_id}
 *   GET  /link-suggestions
 *   PATCH /link-suggestions/{id}/status
 *   POST /link-suggestions/{id}/apply
 *   POST /link-suggestions/bulk-status
 *   POST /update-link-suggestions (n8n callback)
 */
class GKSO_Internal_Linking {

    const TABLE_INDEX       = 'gkso_link_index';
    const TABLE_SUGGESTIONS = 'gkso_link_suggestions';

    const DEFAULT_WEIGHTS = [
        'semantic'  => 0.35,
        'keyword'   => 0.30,
        'authority' => 0.15,
        'orphan'    => 0.10,
        'recency'   => 0.10,
    ];

    const MIN_SCORE_THRESHOLD = 0.62;
    const MIN_SEMANTIC_SCORE  = 0.45;
    const MAX_LINKS_PER_POST  = 6;
    const ORPHAN_INBOUND_MAX  = 10;
    const RECENCY_DAYS        = 60;

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'save_post', [ __CLASS__, 'on_save_post' ], 20, 2 );
        add_action( 'transition_post_status', [ __CLASS__, 'on_status_transition' ], 10, 3 );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
        add_action( 'gkso_reindex_inbound_counts', [ __CLASS__, 'reindex_inbound_counts' ] );
        add_action( 'gkso_index_single_post', [ __CLASS__, 'index_post' ] );

        if ( ! wp_next_scheduled( 'gkso_reindex_inbound_counts' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 03:00:00' ), 'daily', 'gkso_reindex_inbound_counts' );
        }
        
        gkso_log( 'GKSO_Internal_Linking initialized', null, 'INFO' );
    }

    /**
     * Create database tables.
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql_index = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::TABLE_INDEX . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            site_url varchar(255) NOT NULL DEFAULT '',
            tfidf_vector longtext NOT NULL,
            entities text NOT NULL,
            focus_kws text NOT NULL,
            outbound_urls text NOT NULL,
            inbound_count int(10) unsigned NOT NULL DEFAULT 0,
            word_count int(10) unsigned NOT NULL DEFAULT 0,
            post_date datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_post_id (post_id),
            KEY idx_inbound (inbound_count)
        ) {$charset};";

        $sql_suggestions = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::TABLE_SUGGESTIONS . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_post_id bigint(20) unsigned NOT NULL,
            target_post_id bigint(20) unsigned NOT NULL,
            anchor_text varchar(500) NOT NULL,
            target_url varchar(2083) NOT NULL,
            confidence float NOT NULL DEFAULT 0,
            score_semantic float NOT NULL DEFAULT 0,
            score_keyword float NOT NULL DEFAULT 0,
            score_authority float NOT NULL DEFAULT 0,
            score_orphan float NOT NULL DEFAULT 0,
            score_recency float NOT NULL DEFAULT 0,
            method enum('keyword','semantic','ensemble') NOT NULL DEFAULT 'semantic',
            position_hint varchar(100) NOT NULL DEFAULT '',
            char_offset int(10) unsigned NOT NULL DEFAULT 0,
            context_snippet text NOT NULL,
            status enum('pending','approved','rejected','applied') NOT NULL DEFAULT 'pending',
            n8n_run_id varchar(100) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY idx_source (source_post_id, status),
            KEY idx_target (target_post_id),
            KEY idx_status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_index );
        dbDelta( $sql_suggestions );
        
        gkso_log( 'Internal linking tables created/verified', null, 'INFO' );
    }

    // ── Phase 1: Content Indexing ─────────────────────────────────────────────

    public static function on_save_post( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { 
            return; 
        }
        if ( $post->post_status !== 'publish' ) { 
            return; 
        }
        
        $enabled = get_option( 'gkso_enabled_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $post->post_type, $enabled, true ) ) { 
            return; 
        }
        
        wp_schedule_single_event( time() + 5, 'gkso_index_single_post', [ $post_id ] );
    }

    public static function on_status_transition( $new_status, $old_status, $post ) {
        if ( $new_status === 'publish' && $old_status !== 'publish' ) {
            wp_schedule_single_event( time() + 5, 'gkso_index_single_post', [ $post->ID ] );
        }
        if ( $new_status !== 'publish' && $old_status === 'publish' ) {
            self::remove_from_index( $post->ID );
        }
    }

    public static function index_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) { 
            return; 
        }
        
        gkso_log( "Indexing post #{$post_id}", null, 'DEBUG' );

        $content    = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
        $word_count = str_word_count( $content );
        $tfidf      = self::compute_tfidf( $content );
        $entities   = self::extract_entities( $post );
        $focus_kws  = self::get_focus_keywords( $post_id );
        $outbound   = self::extract_outbound_urls( $post->post_content );
        $inbound    = self::count_inbound_links( $post_id );

        global $wpdb;
        $now = current_time( 'mysql' );
        
        $wpdb->replace( 
            $wpdb->prefix . self::TABLE_INDEX, 
            [
                'post_id'       => $post_id,
                'site_url'      => get_permalink( $post_id ),
                'tfidf_vector'  => wp_json_encode( $tfidf ),
                'entities'      => wp_json_encode( $entities ),
                'focus_kws'     => wp_json_encode( $focus_kws ),
                'outbound_urls' => wp_json_encode( $outbound ),
                'inbound_count' => $inbound,
                'word_count'    => $word_count,
                'post_date'     => $post->post_date,
                'updated_at'    => $now,
            ], 
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ] 
        );

        gkso_log( "Post #{$post_id} indexed: {$word_count} words", null, 'INFO' );
    }

    public static function remove_from_index( $post_id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::TABLE_INDEX, [ 'post_id' => $post_id ], [ '%d' ] );
        gkso_log( "Post #{$post_id} removed from index", null, 'INFO' );
    }

    public static function full_reindex() {
        gkso_log( 'Starting full re-index', null, 'INFO' );
        
        $enabled = get_option( 'gkso_enabled_post_types', [ 'post', 'page' ] );
        $posts   = get_posts( [ 
            'post_type'      => $enabled, 
            'post_status'    => 'publish', 
            'posts_per_page' => -1, 
            'fields'         => 'ids' 
        ] );
        
        foreach ( $posts as $pid ) {
            wp_schedule_single_event( time(), 'gkso_index_single_post', [ $pid ] );
        }
        
        gkso_log( 'Queued ' . count( $posts ) . ' posts for re-indexing', null, 'INFO' );
        return count( $posts );
    }

    public static function reindex_inbound_counts() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_INDEX;
        $posts = $wpdb->get_col( "SELECT post_id FROM {$table}" );
        
        foreach ( $posts as $pid ) {
            $count = self::count_inbound_links( (int) $pid );
            $wpdb->update( $table, [ 'inbound_count' => $count ], [ 'post_id' => $pid ], [ '%d' ], [ '%d' ] );
        }
        
        gkso_log( 'Refreshed inbound counts for ' . count( $posts ) . ' posts', null, 'INFO' );
    }

    // ── Phase 2 & 3: Candidate Generation ─────────────────────────────────────

    public static function generate_candidates( $source_post_id, $options = [] ) {
        $post = get_post( $source_post_id );
        if ( ! $post ) { 
            return []; 
        }

        $content    = apply_filters( 'the_content', $post->post_content );
        $plain_text = wp_strip_all_tags( $content );
        $word_count = str_word_count( $plain_text );
        $max_links  = intval( $options['max_links_per_post'] ?? self::MAX_LINKS_PER_POST );

        preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', $content, $em );
        $existing_anchors = array_map( 'strtolower', $em[2] ?? [] );

        $noun_phrases        = self::extract_noun_phrases( $plain_text );
        $kw_candidates       = self::keyword_reverse_lookup( $plain_text, $source_post_id );
        $semantic_candidates = self::semantic_phrase_candidates( $plain_text, $source_post_id );

        $all = array_merge( $noun_phrases, $kw_candidates, $semantic_candidates );

        $filtered            = [];
        $link_positions_used = [];

        foreach ( $all as $candidate ) {
            $anchor       = $candidate['phrase'];
            $anchor_lower = strtolower( $anchor );

            if ( in_array( $anchor_lower, $existing_anchors, true ) ) { continue; }
            if ( str_word_count( $anchor ) < 2 ) { continue; }
            
            // Skip if in headings
            if ( preg_match( '/<h[1-4][^>]*>[^<]*' . preg_quote( $anchor, '/' ) . '[^<]*<\/h[1-4]>/i', $content ) ) { 
                continue; 
            }
            
            // Skip self-references
            if ( isset( $candidate['target_url'] ) && $candidate['target_url'] === get_permalink( $source_post_id ) ) { 
                continue; 
            }

            $word_offset = self::find_phrase_word_offset( $plain_text, $anchor );
            if ( $word_offset === false ) { continue; }

            // Check proximity to existing links
            $too_close = false;
            foreach ( $link_positions_used as $used ) {
                if ( abs( $word_offset - $used ) < 50 ) { 
                    $too_close = true; 
                    break; 
                }
            }
            if ( $too_close ) { continue; }
            if ( count( $link_positions_used ) >= $max_links ) { continue; }

            // Skip first paragraph if configured
            if ( get_option( 'gkso_link_avoid_first_para', true ) && $word_offset < 60 ) { continue; }

            $candidate['word_offset'] = $word_offset;
            $candidate['position']    = self::describe_position( $word_offset, $word_count );

            $filtered[]            = $candidate;
            $link_positions_used[] = $word_offset;
        }

        return $filtered;
    }

    // ── Phase 4: Scoring ──────────────────────────────────────────────────────

    public static function score_candidates( $source_post_id, $candidates, $weights = [] ) {
        if ( empty( $candidates ) ) { 
            return []; 
        }

        $w = array_merge( self::DEFAULT_WEIGHTS, array_filter( $weights ) );

        global $wpdb;
        $table        = $wpdb->prefix . self::TABLE_INDEX;
        $source_entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $source_post_id ), ARRAY_A );
        
        if ( ! $source_entry ) { 
            return []; 
        }

        $targets = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
        $scored  = [];

        foreach ( $candidates as $candidate ) {
            $anchor              = $candidate['phrase'];
            $best_score          = 0;
            $best_target         = null;
            $best_signals        = [];
            $candidate_target_id = $candidate['target_post_id'] ?? null;

            foreach ( $targets as $target ) {
                $tid = (int) $target['post_id'];
                if ( $tid === $source_post_id ) { continue; }
                if ( $candidate_target_id && $tid !== (int) $candidate_target_id ) { continue; }

                $target_tfidf = json_decode( $target['tfidf_vector'] ?? '{}', true ) ?: [];
                $target_kws   = json_decode( $target['focus_kws'] ?? '[]', true ) ?: [];

                $semantic = self::cosine_similarity( self::anchor_to_tfidf( $anchor ), $target_tfidf );
                if ( $semantic < self::MIN_SEMANTIC_SCORE ) { continue; }

                $keyword = self::keyword_alignment_score( $anchor, $target_kws );

                $inbound   = (int) $target['inbound_count'];
                $authority = min( 1.0, 0.5 + ( $inbound / 40 ) );

                if ( $inbound === 0 ) { 
                    $orphan = 1.0; 
                } elseif ( $inbound === 1 ) { 
                    $orphan = 0.8; 
                } elseif ( $inbound >= self::ORPHAN_INBOUND_MAX ) { 
                    $orphan = 0.1; 
                } else { 
                    $orphan = 1.0 - ( $inbound / self::ORPHAN_INBOUND_MAX ) * 0.9; 
                }

                $days_old = ( time() - strtotime( $target['post_date'] ) ) / DAY_IN_SECONDS;
                $recency  = $days_old <= self::RECENCY_DAYS
                    ? 1.0 - ( $days_old / self::RECENCY_DAYS ) * 0.5
                    : max( 0.1, 0.5 - ( ( $days_old - self::RECENCY_DAYS ) / 365 ) * 0.4 );

                $composite = $semantic * $w['semantic'] + 
                            $keyword * $w['keyword'] + 
                            $authority * $w['authority'] + 
                            $orphan * $w['orphan'] + 
                            $recency * $w['recency'];

                if ( $composite > $best_score ) {
                    $best_score   = $composite;
                    $best_target  = $target;
                    $best_signals = compact( 'semantic', 'keyword', 'authority', 'orphan', 'recency' );
                }
            }

            if ( $best_score < self::MIN_SCORE_THRESHOLD || ! $best_target ) { continue; }

            $method = $candidate['method'] ?? 'semantic';
            if ( ( $best_signals['semantic'] ?? 0 ) > 0.85 && ( $best_signals['keyword'] ?? 0 ) > 0.70 ) {
                $method = 'ensemble';
            }

            $scored[] = [
                'anchor_text'     => $anchor,
                'target_post_id'  => (int) $best_target['post_id'],
                'target_url'      => $best_target['site_url'],
                'confidence'      => round( $best_score, 4 ),
                'method'          => $method,
                'position_hint'   => $candidate['position'] ?? '',
                'word_offset'     => $candidate['word_offset'] ?? 0,
                'context_snippet' => $candidate['context'] ?? '',
                'signals'         => $best_signals,
            ];
        }

        usort( $scored, fn( $a, $b ) => $b['confidence'] <=> $a['confidence'] );

        // Deduplicate by target URL
        $used_urls = [];
        $deduped   = [];
        foreach ( $scored as $s ) {
            if ( ! in_array( $s['target_url'], $used_urls, true ) ) {
                $deduped[]   = $s;
                $used_urls[] = $s['target_url'];
            }
        }
        return $deduped;
    }

    // ── Phase 5 & 6: Storage & n8n ────────────────────────────────────────────

    public static function run_analysis( $post_id, $options = [] ) {
        gkso_log( "Running link analysis for post #{$post_id}", null, 'INFO' );
        
        self::index_post( $post_id );
        $candidates = self::generate_candidates( $post_id, $options );
        $scored     = self::score_candidates( $post_id, $candidates, self::get_algorithm_weights() );

        $webhook_url = get_option( 'gkso_n8n_webhook_url' );
        if ( ! empty( $webhook_url ) ) {
            return self::send_to_n8n_for_validation( $post_id, $scored, $options );
        }
        
        return self::store_suggestions( $post_id, $scored );
    }

    private static function send_to_n8n_for_validation( $post_id, $scored, $options ) {
        $payload = [
            'event'             => 'run_link_analysis',
            'post_id'           => $post_id,
            'site_url'          => get_site_url(),
            'candidates'        => $scored,
            'user_instructions' => get_option( 'gkso_link_instructions', '' ),
            'algorithm_config'  => self::get_algorithm_config(),
            'timestamp'         => time(),
        ];
        
        $webhook_url = get_option( 'gkso_n8n_webhook_url' );
        $signature   = self::sign_payload( $payload );
        
        $response = wp_remote_post( $webhook_url, [
            'timeout' => 30,
            'headers' => [ 
                'Content-Type'     => 'application/json', 
                'X-GKSO-Signature' => $signature 
            ],
            'body'    => wp_json_encode( $payload ),
        ] );
        
        if ( is_wp_error( $response ) ) {
            gkso_log( 'n8n webhook failed: ' . $response->get_error_message(), null, 'WARNING' );
            return self::store_suggestions( $post_id, $scored );
        }
        
        gkso_log( "Sent to n8n for post #{$post_id}", null, 'INFO' );
        return $scored;
    }

    /**
     * Sign payload for n8n verification.
     */
    private static function sign_payload( $payload ) {
        $secret = defined( 'GKSO_WEBHOOK_SECRET' ) ? GKSO_WEBHOOK_SECRET : wp_salt();
        return hash_hmac( 'sha256', wp_json_encode( $payload ), $secret );
    }

    public static function store_suggestions( $post_id, $scored, $n8n_run_id = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUGGESTIONS;
        $now   = current_time( 'mysql' );
        $ids   = [];

        // Clear old pending suggestions for this post
        $wpdb->delete( $table, [ 'source_post_id' => $post_id, 'status' => 'pending' ], [ '%d', '%s' ] );

        $auto_approve   = (bool) get_option( 'gkso_link_auto_approve', false );
        $auto_threshold = (float) get_option( 'gkso_link_auto_approve_threshold', 90 ) / 100;

        foreach ( $scored as $s ) {
            $status = ( $auto_approve && $s['confidence'] >= $auto_threshold ) ? 'approved' : 'pending';
            
            $wpdb->insert( $table, [
                'source_post_id'  => $post_id,
                'target_post_id'  => $s['target_post_id'],
                'anchor_text'     => $s['anchor_text'],
                'target_url'      => $s['target_url'],
                'confidence'      => $s['confidence'],
                'score_semantic'  => $s['signals']['semantic'] ?? 0,
                'score_keyword'   => $s['signals']['keyword'] ?? 0,
                'score_authority' => $s['signals']['authority'] ?? 0,
                'score_orphan'    => $s['signals']['orphan'] ?? 0,
                'score_recency'   => $s['signals']['recency'] ?? 0,
                'method'          => $s['method'],
                'position_hint'   => $s['position_hint'],
                'char_offset'     => $s['word_offset'] * 6,
                'context_snippet' => $s['context_snippet'],
                'status'          => $status,
                'n8n_run_id'      => $n8n_run_id,
                'created_at'      => $now,
                'updated_at'      => $now,
            ] );
            
            $ids[] = $wpdb->insert_id;
        }
        
        gkso_log( count( $ids ) . " suggestions stored for post #{$post_id}", null, 'INFO' );
        return $ids;
    }

    public static function apply_suggestion( $suggestion_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUGGESTIONS;
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $suggestion_id ), ARRAY_A );
        
        if ( ! $row ) { 
            return new WP_Error( 'not_found', __( 'Suggestion not found.', 'gemini-kimi-seo' ) ); 
        }
        if ( $row['status'] !== 'approved' ) { 
            return new WP_Error( 'not_approved', __( 'Must be approved first.', 'gemini-kimi-seo' ) ); 
        }

        $post = get_post( (int) $row['source_post_id'] );
        if ( ! $post ) { 
            return new WP_Error( 'post_not_found', __( 'Post not found.', 'gemini-kimi-seo' ) ); 
        }

        $anchor  = esc_html( $row['anchor_text'] );
        $url     = esc_url( $row['target_url'] );
        $link    = "<a href=\"{$url}\">{$anchor}</a>";
        $pattern = '/(?<!["\'>])(' . preg_quote( $row['anchor_text'], '/' ) . ')(?![^<]*<\/a>)/';
        $new_content = preg_replace( $pattern, $link, $post->post_content, 1, $count );

        if ( ! $count ) { 
            return new WP_Error( 'not_replaced', __( 'Anchor text not found.', 'gemini-kimi-seo' ) ); 
        }

        wp_update_post( [ 'ID' => $post->ID, 'post_content' => $new_content ] );

        $wpdb->update( $table, [ 'status' => 'applied', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $suggestion_id ] );

        // Update outbound/inbound counts
        $outbound = json_decode( $wpdb->get_var( $wpdb->prepare( 
            "SELECT outbound_urls FROM {$wpdb->prefix}" . self::TABLE_INDEX . " WHERE post_id = %d", 
            $post->ID 
        ) ), true ) ?: [];
        
        $outbound[] = $row['target_url'];
        $wpdb->update( $wpdb->prefix . self::TABLE_INDEX, [ 
            'outbound_urls' => wp_json_encode( array_unique( $outbound ) ) 
        ], [ 'post_id' => $post->ID ] );
        
        $wpdb->query( $wpdb->prepare( 
            "UPDATE {$wpdb->prefix}" . self::TABLE_INDEX . " SET inbound_count = inbound_count + 1 WHERE post_id = %d", 
            (int) $row['target_post_id'] 
        ) );

        gkso_log( "Suggestion #{$suggestion_id} applied", null, 'INFO' );
        return true;
    }

    // ── REST Routes ───────────────────────────────────────────────────────────

    public static function register_rest_routes() {
        $ns = GKSO_REST_NAMESPACE; // Uses your defined constant: gemini-kimi-seo/v1

        // Index stats
        register_rest_route( $ns, '/link-index-stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'rest_link_index_stats' ],
            'permission_callback' => fn() => current_user_can( 'seo_view_tests' ),
        ] );

        // Algorithm settings
        register_rest_route( $ns, '/link-algorithm-settings', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'rest_get_algorithm_settings' ],
                'permission_callback' => fn() => current_user_can( 'seo_view_tests' ),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'rest_save_algorithm_settings' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
            ],
        ] );

        // Instructions
        register_rest_route( $ns, '/link-instructions', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'rest_get_instructions' ],
                'permission_callback' => fn() => current_user_can( 'seo_view_tests' ),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'rest_save_instructions' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
            ],
        ] );

        // Pillar pages
        register_rest_route( $ns, '/link-pillar-pages', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'rest_get_pillar_pages' ],
                'permission_callback' => fn() => current_user_can( 'seo_view_tests' ),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'rest_save_pillar_pages' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
            ],
        ] );

        // Reindex
        register_rest_route( $ns, '/rebuild-link-index', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'rest_reindex' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        // Run analysis
        register_rest_route( $ns, '/run-link-analysis', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'rest_run_analysis' ],
            'permission_callback' => fn() => current_user_can( 'seo_optimize' ),
            'args'                => [ 
                'post_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] 
            ],
        ] );

        // Suggestions
        register_rest_route( $ns, '/link-suggestions/(?P<post_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'rest_get_suggestions' ],
            'permission_callback' => fn() => current_user_can( 'seo_view_tests' ),
        ] );

        register_rest_route( $ns, '/link-suggestions', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'rest_get_all_suggestions' ],
            'permission_callback' => fn() => current_user_can( 'seo_view_tests' ),
        ] );

        register_rest_route( $ns, '/link-suggestions/(?P<id>\d+)/status', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [ __CLASS__, 'rest_update_status' ],
            'permission_callback' => fn() => current_user_can( 'seo_optimize' ),
            'args'                => [ 
                'status' => [ 'required' => true, 'type' => 'string', 'enum' => [ 'approved', 'rejected', 'pending' ] ] 
            ],
        ] );

        register_rest_route( $ns, '/link-suggestions/(?P<id>\d+)/apply', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'rest_apply_suggestion' ],
            'permission_callback' => fn() => current_user_can( 'seo_optimize' ),
        ] );

        register_rest_route( $ns, '/link-suggestions/bulk-status', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [ __CLASS__, 'rest_bulk_status' ],
            'permission_callback' => fn() => current_user_can( 'seo_optimize' ),
            'args'                => [
                'ids'    => [ 'required' => true, 'type' => 'array' ],
                'status' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        // n8n callback
        register_rest_route( $ns, '/update-link-suggestions', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'rest_n8n_callback' ],
            'permission_callback' => [ __CLASS__, 'verify_n8n_permission' ],
        ] );

        // Legacy routes for backward compat
        register_rest_route( $ns, '/link-index/health', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'rest_link_index_stats' ],
            'permission_callback' => fn() => current_user_can( 'seo_view_tests' ),
        ] );

        register_rest_route( $ns, '/link-config', [
            'methods'             => WP_REST_Server::READABLE . ',' . WP_REST_Server::EDITABLE,
            'callback'            => [ __CLASS__, 'rest_link_config' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
    }

    // ── REST Callbacks ────────────────────────────────────────────────────────

    public static function rest_link_index_stats() {
        global $wpdb;
        $it = $wpdb->prefix . self::TABLE_INDEX;
        $st = $wpdb->prefix . self::TABLE_SUGGESTIONS;

        return rest_ensure_response( [
            'posts_indexed'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$it}" ),
            'total_posts'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page')" ),
            'links_mapped'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$st} WHERE status='applied'" ),
            'orphan_count'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$it} WHERE inbound_count <= 1" ),
            'avg_links_post' => round( (float) $wpdb->get_var( "SELECT AVG(inbound_count) FROM {$it}" ), 1 ),
        ] );
    }

    public static function rest_get_algorithm_settings() {
        return rest_ensure_response( [
            'min_confidence'         => (int) get_option( 'gkso_link_min_confidence', 62 ),
            'max_links_per_post'     => (int) get_option( 'gkso_link_max_per_post', 6 ),
            'min_words_between'      => (int) get_option( 'gkso_link_min_word_distance', 150 ),
            'semantic_weight'        => (int) get_option( 'gkso_link_weight_semantic', 35 ),
            'keyword_weight'         => (int) get_option( 'gkso_link_weight_keyword', 30 ),
            'authority_weight'       => (int) get_option( 'gkso_link_weight_authority', 15 ),
            'orphan_weight'          => (int) get_option( 'gkso_link_weight_orphan', 10 ),
            'recency_weight'         => (int) get_option( 'gkso_link_weight_recency', 10 ),
            'auto_approve'           => (bool) get_option( 'gkso_link_auto_approve', false ),
            'auto_approve_threshold' => (int) get_option( 'gkso_link_auto_approve_threshold', 90 ),
            'avoid_headings'         => (bool) get_option( 'gkso_link_avoid_headings', true ),
            'avoid_first_para'       => (bool) get_option( 'gkso_link_avoid_first_para', true ),
            'avoid_blockquotes'      => (bool) get_option( 'gkso_link_avoid_blockquotes', true ),
            'prefer_early'           => (bool) get_option( 'gkso_link_prefer_early', true ),
            'one_url_per_post'       => (bool) get_option( 'gkso_link_one_url_per_post', true ),
        ] );
    }

    public static function rest_save_algorithm_settings( WP_REST_Request $req ) {
        $body = $req->get_json_params() ?: [];

        $map = [
            'min_confidence'         => [ 'gkso_link_min_confidence', 'int' ],
            'max_links_per_post'     => [ 'gkso_link_max_per_post', 'int' ],
            'min_words_between'      => [ 'gkso_link_min_word_distance', 'int' ],
            'semantic_weight'        => [ 'gkso_link_weight_semantic', 'int' ],
            'keyword_weight'         => [ 'gkso_link_weight_keyword', 'int' ],
            'authority_weight'       => [ 'gkso_link_weight_authority', 'int' ],
            'orphan_weight'          => [ 'gkso_link_weight_orphan', 'int' ],
            'recency_weight'         => [ 'gkso_link_weight_recency', 'int' ],
            'auto_approve'           => [ 'gkso_link_auto_approve', 'bool' ],
            'auto_approve_threshold' => [ 'gkso_link_auto_approve_threshold', 'int' ],
            'avoid_headings'         => [ 'gkso_link_avoid_headings', 'bool' ],
            'avoid_first_para'       => [ 'gkso_link_avoid_first_para', 'bool' ],
            'avoid_blockquotes'      => [ 'gkso_link_avoid_blockquotes', 'bool' ],
            'prefer_early'           => [ 'gkso_link_prefer_early', 'bool' ],
            'one_url_per_post'       => [ 'gkso_link_one_url_per_post', 'bool' ],
        ];

        foreach ( $map as $js_key => [ $option, $type ] ) {
            $val = $body[ $js_key ] ?? $req->get_param( $js_key );
            if ( null === $val ) { continue; }
            update_option( $option, $type === 'bool' ? (bool) $val : (int) $val );
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    public static function rest_get_instructions() {
        return rest_ensure_response( [ 'instructions' => get_option( 'gkso_link_instructions', '' ) ] );
    }

    public static function rest_save_instructions( WP_REST_Request $req ) {
        $body = $req->get_json_params() ?: [];
        update_option( 'gkso_link_instructions', sanitize_textarea_field( $body['instructions'] ?? '' ) );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public static function rest_get_pillar_pages() {
        $pages  = get_option( 'gkso_pillar_pages', [] );
        $result = [];

        global $wpdb;
        $it = $wpdb->prefix . self::TABLE_INDEX;

        foreach ( (array) $pages as $item ) {
            if ( is_array( $item ) ) {
                $result[] = $item;
            } else {
                $pid   = (int) $item;
                $entry = $wpdb->get_row( $wpdb->prepare( "SELECT inbound_count FROM {$it} WHERE post_id = %d", $pid ), ARRAY_A );
                $result[] = [
                    'id'           => $pid,
                    'title'        => get_the_title( $pid ),
                    'url'          => get_permalink( $pid ),
                    'inboundLinks' => $entry ? (int) $entry['inbound_count'] : 0,
                    'priority'     => 'high',
                ];
            }
        }

        return rest_ensure_response( [ 'pillar_pages' => $result ] );
    }

    public static function rest_save_pillar_pages( WP_REST_Request $req ) {
        $body = $req->get_json_params() ?: [];
        if ( isset( $body['pillar_pages'] ) ) {
            update_option( 'gkso_pillar_pages', $body['pillar_pages'] );
        }
        return rest_ensure_response( [ 'success' => true ] );
    }

    public static function rest_run_analysis( WP_REST_Request $req ) {
        $pid = $req->get_param( 'post_id' );
        if ( ! get_post( $pid ) ) { 
            return new WP_Error( 'not_found', 'Post not found.', [ 'status' => 404 ] ); 
        }
        $result = self::run_analysis( $pid, $req->get_params() );
        return rest_ensure_response( [ 'success' => true, 'suggestions' => $result ] );
    }

    public static function rest_get_suggestions( WP_REST_Request $req ) {
        global $wpdb;
        $pid  = (int) $req->get_param( 'post_id' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE_SUGGESTIONS . " WHERE source_post_id = %d ORDER BY confidence DESC", 
            $pid
        ), ARRAY_A );
        return rest_ensure_response( self::format_rows( $rows ) );
    }

    public static function rest_get_all_suggestions( WP_REST_Request $req ) {
        global $wpdb;
        $status = sanitize_text_field( $req->get_param( 'status' ) ?? '' );
        $where  = $status ? $wpdb->prepare( 'WHERE s.status = %s', $status ) : '';
        $rows   = $wpdb->get_results(
            "SELECT s.*, p.post_title as source_post_title, t.post_title as target_post_title
             FROM {$wpdb->prefix}" . self::TABLE_SUGGESTIONS . " s
             LEFT JOIN {$wpdb->posts} p ON p.ID = s.source_post_id
             LEFT JOIN {$wpdb->posts} t ON t.ID = s.target_post_id
             {$where} ORDER BY s.confidence DESC LIMIT 500",
            ARRAY_A
        );
        return rest_ensure_response( self::format_rows( $rows ) );
    }

    public static function rest_update_status( WP_REST_Request $req ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE_SUGGESTIONS,
            [ 'status' => $req->get_param( 'status' ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $req->get_param( 'id' ) ]
        );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public static function rest_apply_suggestion( WP_REST_Request $req ) {
        $result = self::apply_suggestion( (int) $req->get_param( 'id' ) );
        return is_wp_error( $result ) ? $result : rest_ensure_response( [ 'success' => true ] );
    }

    public static function rest_bulk_status( WP_REST_Request $req ) {
        global $wpdb;
        $ids    = array_map( 'absint', (array) $req->get_param( 'ids' ) );
        $status = $req->get_param( 'status' );
        
        if ( empty( $ids ) ) { 
            return new WP_Error( 'empty', 'No IDs.', [ 'status' => 400 ] ); 
        }
        
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}" . self::TABLE_SUGGESTIONS . " SET status = %s, updated_at = %s WHERE id IN ({$placeholders})",
            array_merge( [ $status, current_time( 'mysql' ) ], $ids )
        ) );
        
        return rest_ensure_response( [ 'success' => true, 'count' => count( $ids ) ] );
    }

    public static function rest_n8n_callback( WP_REST_Request $req ) {
        $body = $req->get_json_params();
        $pid  = absint( $body['post_id'] ?? 0 );
        $list = $body['validated_suggestions'] ?? [];
        $run  = sanitize_text_field( $body['n8n_execution_id'] ?? '' );
        
        if ( ! $pid || empty( $list ) ) { 
            return new WP_Error( 'invalid', 'Missing data.', [ 'status' => 400 ] ); 
        }
        
        $ids = self::store_suggestions( $pid, $list, $run );
        return rest_ensure_response( [ 'success' => true, 'stored' => count( $ids ) ] );
    }

    public static function rest_reindex() {
        $indexed = self::full_reindex();
        return rest_ensure_response( [ 'success' => true, 'queued' => $indexed ] );
    }

    public static function rest_link_config( WP_REST_Request $req ) {
        if ( in_array( $req->get_method(), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            return self::rest_save_algorithm_settings( $req );
        }
        return rest_ensure_response( self::get_algorithm_config() );
    }

    public static function verify_n8n_permission( WP_REST_Request $req ) {
        // Check IP allowlist if configured
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $whitelist = apply_filters( 'gkso_n8n_ip_whitelist', [] );
        
        if ( ! empty( $whitelist ) && ! in_array( $ip, $whitelist, true ) ) {
            return new WP_Error( 'forbidden', 'IP not allowed', [ 'status' => 403 ] );
        }

        // Verify signature
        $header = $req->get_header( 'x-gkso-signature' );
        $body   = $req->get_body();
        $secret = defined( 'GKSO_WEBHOOK_SECRET' ) ? GKSO_WEBHOOK_SECRET : wp_salt();
        $expected = hash_hmac( 'sha256', $body, $secret );
        
        if ( ! hash_equals( $expected, $header ) ) {
            return new WP_Error( 'unauthorized', 'Invalid signature', [ 'status' => 401 ] );
        }
        
        return true;
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    private static function compute_tfidf( $text ) {
        $words = preg_split( '/[^a-z0-9]+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
        $stop  = [ 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'is', 'are', 'was', 'were', 'be', 'been', 'it', 'its', 'this', 'that', 'by', 'as', 'from' ];
        $tf    = [];
        
        foreach ( $words as $w ) {
            if ( strlen( $w ) < 3 || in_array( $w, $stop, true ) ) { continue; }
            $tf[ $w ] = ( $tf[ $w ] ?? 0 ) + 1;
        }
        
        if ( empty( $tf ) ) { return []; }
        
        $total = array_sum( $tf );
        foreach ( $tf as $k => $v ) { 
            $tf[ $k ] = round( $v / $total, 6 ); 
        }
        
        arsort( $tf );
        return array_slice( $tf, 0, 200 );
    }

    private static function cosine_similarity( $a, $b ) {
        if ( empty( $a ) || empty( $b ) ) { return 0.0; }
        
        $dot = $ma = $mb = 0.0;
        foreach ( $a as $k => $v ) { 
            $dot += $v * ( $b[ $k ] ?? 0 ); 
            $ma  += $v * $v; 
        }
        foreach ( $b as $v ) { 
            $mb += $v * $v; 
        }
        
        $denom = sqrt( $ma ) * sqrt( $mb );
        return $denom > 0 ? min( 1.0, $dot / $denom ) : 0.0;
    }

    private static function anchor_to_tfidf( $phrase ) {
        $words = preg_split( '/[^a-z0-9]+/', strtolower( $phrase ), -1, PREG_SPLIT_NO_EMPTY );
        $tf    = [];
        
        foreach ( $words as $w ) { 
            $tf[ $w ] = ( $tf[ $w ] ?? 0 ) + 1; 
        }
        
        $total = array_sum( $tf );
        foreach ( $tf as $k => $v ) { 
            $tf[ $k ] = $v / $total; 
        }
        
        return $tf;
    }

    private static function keyword_alignment_score( $anchor, array $kws ) {
        if ( empty( $kws ) ) { return 0.3; }
        
        $al   = strtolower( $anchor );
        $best = 0.0;
        
        foreach ( $kws as $kw ) {
            $kl = strtolower( (string) $kw );
            if ( $al === $kl ) { return 1.0; }
            
            if ( strpos( $al, $kl ) !== false || strpos( $kl, $al ) !== false ) { 
                $best = max( $best, 0.75 ); 
                continue; 
            }
            
            $aw      = explode( ' ', $al );
            $kw_arr  = explode( ' ', $kl );
            $overlap = count( array_intersect( $aw, $kw_arr ) );
            
            if ( $overlap ) { 
                $best = max( $best, 0.3 + ( $overlap / max( count( $aw ), count( $kw_arr ) ) ) * 0.45 ); 
            }
        }
        
        return round( $best, 4 );
    }

    private static function extract_noun_phrases( $text ) {
        $sentences = preg_split( '/[.!?]+/', $text );
        $phrases   = [];
        
        foreach ( $sentences as $sent ) {
            $sent  = trim( $sent );
            $words = preg_split( '/\s+/', $sent, -1, PREG_SPLIT_NO_EMPTY );
            $n     = count( $words );
            
            for ( $len = 2; $len <= 5; $len++ ) {
                for ( $i = 0; $i <= $n - $len; $i++ ) {
                    $chunk = implode( ' ', array_slice( $words, $i, $len ) );
                    if ( preg_match( '/[",;:()[\]{}<>]/', $chunk ) ) { continue; }
                    
                    $pos     = strpos( $sent, $chunk );
                    $context = '...' . substr( $sent, max( 0, (int) $pos - 60 ), 140 ) . '...';
                    $phrases[] = [ 'phrase' => $chunk, 'method' => 'semantic', 'context' => $context ];
                }
            }
        }
        
        $seen = $unique = [];
        foreach ( $phrases as $p ) {
            $key = strtolower( $p['phrase'] );
            if ( ! isset( $seen[ $key ] ) ) { 
                $seen[ $key ] = true; 
                $unique[] = $p; 
            }
        }
        
        return array_slice( $unique, 0, 100 );
    }

    private static function keyword_reverse_lookup( $text, $source_post_id ) {
        global $wpdb;
        $entries    = $wpdb->get_results( "SELECT post_id, site_url, focus_kws FROM {$wpdb->prefix}" . self::TABLE_INDEX, ARRAY_A );
        $tl         = strtolower( $text );
        $candidates = [];
        
        foreach ( $entries as $entry ) {
            if ( (int) $entry['post_id'] === $source_post_id ) { continue; }
            
            $kws = json_decode( $entry['focus_kws'] ?? '[]', true ) ?: [];
            foreach ( $kws as $kw ) {
                if ( strlen( (string) $kw ) < 4 ) { continue; }
                
                if ( strpos( $tl, strtolower( (string) $kw ) ) !== false ) {
                    $pos     = strpos( $tl, strtolower( (string) $kw ) );
                    $context = '...' . substr( $text, max( 0, (int) $pos - 60 ), 140 ) . '...';
                    $candidates[] = [
                        'phrase'         => $kw, 
                        'method'         => 'keyword', 
                        'target_post_id' => (int) $entry['post_id'], 
                        'target_url'     => $entry['site_url'], 
                        'context'        => $context 
                    ];
                    break;
                }
            }
        }
        return $candidates;
    }

    private static function semantic_phrase_candidates( $text, $source_post_id ) {
        $tfidf      = self::compute_tfidf( $text );
        arsort( $tfidf );
        $top        = array_slice( array_keys( $tfidf ), 0, 20 );
        $phrases    = self::extract_noun_phrases( $text );
        $candidates = [];
        
        foreach ( $phrases as $p ) {
            $pl = strtolower( $p['phrase'] );
            foreach ( $top as $term ) {
                if ( strpos( $pl, $term ) !== false ) { 
                    $candidates[] = array_merge( $p, [ 'method' => 'semantic' ] ); 
                    break; 
                }
            }
        }
        return $candidates;
    }

    private static function get_focus_keywords( $post_id ) {
        $kws = [];
        
        $yoast = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
        if ( $yoast ) { $kws[] = $yoast; }
        
        $rm = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        if ( $rm ) { 
            foreach ( explode( ',', $rm ) as $k ) { 
                $kws[] = trim( $k ); 
            } 
        }
        
        $aio = get_post_meta( $post_id, '_aioseo_keywords', true );
        if ( $aio ) {
            $d = json_decode( $aio, true );
            if ( is_array( $d ) ) { 
                foreach ( $d as $k ) { 
                    $kws[] = is_array( $k ) ? ( $k['label'] ?? '' ) : $k; 
                } 
            }
        }
        
        $kws[] = get_the_title( $post_id );
        return array_values( array_filter( array_unique( $kws ) ) );
    }

    private static function extract_outbound_urls( $content ) {
        preg_match_all( '/href=["\']([^"\'#]+)["\']/', $content, $m );
        return array_values( array_unique( $m[1] ?? [] ) );
    }

    private static function count_inbound_links( $post_id ) {
        global $wpdb;
        $target = get_permalink( $post_id );
        if ( ! $target ) { return 0; }
        
        $rows  = $wpdb->get_col( "SELECT outbound_urls FROM {$wpdb->prefix}" . self::TABLE_INDEX );
        $count = 0;
        
        foreach ( $rows as $json ) {
            $urls = json_decode( $json, true ) ?: [];
            if ( in_array( $target, $urls, true ) ) { $count++; }
        }
        return $count;
    }

    private static function find_phrase_word_offset( $text, $phrase ) {
        $pos = stripos( $text, $phrase );
        if ( $pos === false ) { return false; }
        return str_word_count( substr( $text, 0, $pos ) );
    }

    private static function describe_position( $word_offset, $total_words ) {
        if ( $total_words <= 0 ) { return 'paragraph 1'; }
        
        $ratio = $word_offset / $total_words;
        if ( $ratio < 0.33 ) { return 'paragraph 1–3 (early)'; }
        if ( $ratio < 0.66 ) { return 'paragraph 4–6 (middle)'; }
        return 'paragraph 7+ (late)';
    }

    private static function extract_entities( $post ) {
        $entities = [];
        
        foreach ( preg_split( '/\s+/', $post->post_title ) as $w ) {
            if ( strlen( $w ) > 3 && ctype_upper( $w[0] ) ) { 
                $entities[] = $w; 
            }
        }
        
        preg_match_all( '/<h2[^>]*>([^<]+)<\/h2>/i', $post->post_content, $h2s );
        foreach ( $h2s[1] as $h ) { 
            $entities[] = wp_strip_all_tags( $h ); 
        }
        
        return array_values( array_unique( array_filter( $entities ) ) );
    }

    private static function get_algorithm_weights() {
        return [
            'semantic'  => (float) get_option( 'gkso_link_weight_semantic', 35 ) / 100,
            'keyword'   => (float) get_option( 'gkso_link_weight_keyword', 30 ) / 100,
            'authority' => (float) get_option( 'gkso_link_weight_authority', 15 ) / 100,
            'orphan'    => (float) get_option( 'gkso_link_weight_orphan', 10 ) / 100,
            'recency'   => (float) get_option( 'gkso_link_weight_recency', 10 ) / 100,
        ];
    }

    public static function get_algorithm_config() {
        return [
            'min_confidence'         => (float) get_option( 'gkso_link_min_confidence', 62 ) / 100,
            'max_links_per_post'     => (int) get_option( 'gkso_link_max_per_post', 6 ),
            'min_word_distance'      => (int) get_option( 'gkso_link_min_word_distance', 150 ),
            'weight_semantic'        => (int) get_option( 'gkso_link_weight_semantic', 35 ),
            'weight_keyword'         => (int) get_option( 'gkso_link_weight_keyword', 30 ),
            'weight_authority'       => (int) get_option( 'gkso_link_weight_authority', 15 ),
            'weight_orphan'          => (int) get_option( 'gkso_link_weight_orphan', 10 ),
            'weight_recency'         => (int) get_option( 'gkso_link_weight_recency', 10 ),
            'auto_approve'           => (bool) get_option( 'gkso_link_auto_approve', false ),
            'auto_approve_threshold' => (float) get_option( 'gkso_link_auto_approve_threshold', 90 ),
            'avoid_headings'         => (bool) get_option( 'gkso_link_avoid_headings', true ),
            'avoid_first_paragraph'  => (bool) get_option( 'gkso_link_avoid_first_para', true ),
            'avoid_blockquotes'      => (bool) get_option( 'gkso_link_avoid_blockquotes', true ),
            'prefer_early_placement' => (bool) get_option( 'gkso_link_prefer_early', true ),
            'one_url_per_post'       => (bool) get_option( 'gkso_link_one_url_per_post', true ),
            'user_instructions'      => get_option( 'gkso_link_instructions', '' ),
            'pillar_page_ids'        => get_option( 'gkso_pillar_pages', [] ),
        ];
    }

    private static function format_rows( $rows ) {
        return array_map( function( $r ) {
            return [
                'id'           => (int) $r['id'],
                'sourcePostId' => (int) $r['source_post_id'],
                'targetPostId' => (int) $r['target_post_id'],
                'postTitle'    => $r['source_post_title'] ?? get_the_title( (int) $r['source_post_id'] ),
                'targetTitle'  => $r['target_post_title'] ?? get_the_title( (int) $r['target_post_id'] ),
                'anchor'       => $r['anchor_text'],
                'targetUrl'    => $r['target_url'],
                'confidence'   => (float) $r['confidence'],
                'method'       => $r['method'],
                'position'     => $r['position_hint'],
                'context'      => $r['context_snippet'],
                'status'       => $r['status'],
                'signals'      => [
                    'semantic'  => (float) $r['score_semantic'],
                    'keyword'   => (float) $r['score_keyword'],
                    'authority' => (float) $r['score_authority'],
                    'orphan'    => (float) $r['score_orphan'],
                    'recency'   => (float) $r['score_recency'],
                ],
            ];
        }, $rows );
    }
}