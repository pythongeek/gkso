<?php
/**
 * GKSO_SEO_Integrations class
 * 
 * Auto-detects Yoast SEO, Rank Math, or AIOSEO and writes the correct meta keys.
 * Provides fallback to generic keys.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_SEO_Integrations {
    
    /**
     * Detect which SEO plugin is active
     * 
     * @return string Detected plugin: 'yoast', 'rankmath', 'aioseo', or 'generic'
     */
    public static function detect_seo_plugin() {
        // Check for Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) ) {
            return 'yoast';
        }
        
        // Check for Rank Math
        if ( class_exists( 'RankMath' ) ) {
            return 'rankmath';
        }
        
        // Check for AIOSEO
        if ( function_exists( 'aioseo' ) ) {
            return 'aioseo';
        }
        
        // Default to generic
        return 'generic';
    }
    
    /**
     * Update SEO meta for a post
     * 
     * @param int    $post_id The post ID
     * @param string $title   The SEO title
     * @param string $description The SEO description
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public static function update_seo_meta( $post_id, $title, $description ) {
        $plugin = self::detect_seo_plugin();
        
        switch ( $plugin ) {
            case 'yoast':
                return self::update_yoast_meta( $post_id, $title, $description );
                
            case 'rankmath':
                return self::update_rankmath_meta( $post_id, $title, $description );
                
            case 'aioseo':
                return self::update_aioseo_meta( $post_id, $title, $description );
                
            case 'generic':
            default:
                return self::update_generic_meta( $post_id, $title, $description );
        }
    }
    
    /**
     * Get current SEO meta for a post
     * 
     * @param int $post_id The post ID
     * @return array Array with 'title', 'description', and 'plugin' keys
     */
    public static function get_current_seo_meta( $post_id ) {
        $plugin = self::detect_seo_plugin();
        
        switch ( $plugin ) {
            case 'yoast':
                return self::get_yoast_meta( $post_id );
                
            case 'rankmath':
                return self::get_rankmath_meta( $post_id );
                
            case 'aioseo':
                return self::get_aioseo_meta( $post_id );
                
            case 'generic':
            default:
                return self::get_generic_meta( $post_id );
        }
    }
    
    /**
     * Update Yoast SEO meta
     * 
     * @param int    $post_id The post ID
     * @param string $title   The SEO title
     * @param string $description The SEO description
     * @return true|WP_Error True on success, WP_Error on failure
     */
    private static function update_yoast_meta( $post_id, $title, $description ) {
        // Update Yoast meta fields
        update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $title ) );
        update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $description ) );
        
        // Invalidate Yoast cache if available
        if ( function_exists( 'YoastSEO' ) && is_callable( [ YoastSEO()->helpers, 'indexable' ] ) ) {
            try {
                YoastSEO()->helpers->indexable->invalidate( $post_id );
            } catch ( Exception $e ) {
                // Log but don't fail
                error_log( 'GKSO: Failed to invalidate Yoast indexable: ' . $e->getMessage() );
            }
        }
        
        // Update indexable via API if available
        if ( class_exists( 'WPSEO_Indexable' ) && is_callable( [ 'WPSEO_Indexable', 'reset_permalink' ] ) ) {
            try {
                WPSEO_Indexable::reset_permalink( $post_id );
            } catch ( Exception $e ) {
                error_log( 'GKSO: Failed to reset Yoast permalink: ' . $e->getMessage() );
            }
        }
        
        do_action( 'gkso_seo_meta_updated', $post_id, $title, $description, 'yoast' );
        
        return true;
    }
    
    /**
     * Get Yoast SEO meta
     * 
     * @param int $post_id The post ID
     * @return array Array with 'title', 'description', and 'plugin' keys
     */
    private static function get_yoast_meta( $post_id ) {
        $title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
        $description = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        
        // Fallback to post title if no SEO title set
        if ( empty( $title ) ) {
            $post = get_post( $post_id );
            if ( $post ) {
                $title = $post->post_title;
            }
        }
        
        return [
            'title'       => $title,
            'description' => $description,
            'plugin'      => 'yoast',
        ];
    }
    
    /**
     * Update Rank Math meta
     * 
     * @param int    $post_id The post ID
     * @param string $title   The SEO title
     * @param string $description The SEO description
     * @return true|WP_Error True on success, WP_Error on failure
     */
    private static function update_rankmath_meta( $post_id, $title, $description ) {
        // Update Rank Math meta fields
        update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $title ) );
        update_post_meta( $post_id, 'rank_math_description', sanitize_textarea_field( $description ) );
        
        // Invalidate Rank Math cache if available
        if ( class_exists( 'RankMath\Helper' ) && is_callable( [ 'RankMath\Helper', 'invalidate_object' ] ) ) {
            try {
                \RankMath\Helper::invalidate_object( $post_id, 'post' );
            } catch ( Exception $e ) {
                error_log( 'GKSO: Failed to invalidate Rank Math object: ' . $e->getMessage() );
            }
        }
        
        // Trigger SEO score recalculation if filter available
        if ( has_filter( 'rank_math/seo_score' ) ) {
            do_action( 'rank_math/seo_score/recalculate', $post_id );
        }
        
        do_action( 'gkso_seo_meta_updated', $post_id, $title, $description, 'rankmath' );
        
        return true;
    }
    
    /**
     * Get Rank Math SEO meta
     * 
     * @param int $post_id The post ID
     * @return array Array with 'title', 'description', and 'plugin' keys
     */
    private static function get_rankmath_meta( $post_id ) {
        $title = get_post_meta( $post_id, 'rank_math_title', true );
        $description = get_post_meta( $post_id, 'rank_math_description', true );
        
        // Fallback to post title if no SEO title set
        if ( empty( $title ) ) {
            $post = get_post( $post_id );
            if ( $post ) {
                $title = $post->post_title;
            }
        }
        
        return [
            'title'       => $title,
            'description' => $description,
            'plugin'      => 'rankmath',
        ];
    }
    
    /**
     * Update AIOSEO meta
     * 
     * @param int    $post_id The post ID
     * @param string $title   The SEO title
     * @param string $description The SEO description
     * @return true|WP_Error True on success, WP_Error on failure
     */
    private static function update_aioseo_meta( $post_id, $title, $description ) {
        // Try to use AIOSEO API if available
        if ( function_exists( 'aioseo' ) && is_object( aioseo() ) ) {
            // Try the savePostMeta method
            if ( is_callable( [ aioseo()->updates, 'savePostMeta' ] ) ) {
                try {
                    aioseo()->updates->savePostMeta( $post_id, [
                        'title'       => sanitize_text_field( $title ),
                        'description' => sanitize_textarea_field( $description ),
                    ] );
                } catch ( Exception $e ) {
                    error_log( 'GKSO: AIOSEO savePostMeta failed: ' . $e->getMessage() );
                    // Fall through to direct meta update
                }
            } else {
                // Fallback to direct meta update
                update_post_meta( $post_id, '_aioseo_title', sanitize_text_field( $title ) );
                update_post_meta( $post_id, '_aioseo_description', sanitize_textarea_field( $description ) );
            }
            
            // Notify AIOSEO of update if helper available
            if ( is_callable( [ aioseo()->helpers, 'notifyBlogPostUpdated' ] ) ) {
                try {
                    aioseo()->helpers->notifyBlogPostUpdated( $post_id );
                } catch ( Exception $e ) {
                    error_log( 'GKSO: Failed to notify AIOSEO: ' . $e->getMessage() );
                }
            }
        } else {
            // Direct meta fallback
            update_post_meta( $post_id, '_aioseo_title', sanitize_text_field( $title ) );
            update_post_meta( $post_id, '_aioseo_description', sanitize_textarea_field( $description ) );
        }
        
        do_action( 'gkso_seo_meta_updated', $post_id, $title, $description, 'aioseo' );
        
        return true;
    }
    
    /**
     * Get AIOSEO meta
     * 
     * @param int $post_id The post ID
     * @return array Array with 'title', 'description', and 'plugin' keys
     */
    private static function get_aioseo_meta( $post_id ) {
        $title = get_post_meta( $post_id, '_aioseo_title', true );
        $description = get_post_meta( $post_id, '_aioseo_description', true );
        
        // Fallback to post title if no SEO title set
        if ( empty( $title ) ) {
            $post = get_post( $post_id );
            if ( $post ) {
                $title = $post->post_title;
            }
        }
        
        return [
            'title'       => $title,
            'description' => $description,
            'plugin'      => 'aioseo',
        ];
    }
    
    /**
     * Update generic SEO meta (fallback)
     * 
     * @param int    $post_id The post ID
     * @param string $title   The SEO title
     * @param string $description The SEO description
     * @return true|WP_Error True on success, WP_Error on failure
     */
    private static function update_generic_meta( $post_id, $title, $description ) {
        update_post_meta( $post_id, '_seo_title', sanitize_text_field( $title ) );
        update_post_meta( $post_id, '_seo_description', sanitize_textarea_field( $description ) );
        
        // Fire action for third-party plugins to hook into
        do_action( 'gkso_seo_meta_updated', $post_id, $title, $description, 'generic' );
        
        return true;
    }
    
    /**
     * Get generic SEO meta (fallback)
     * 
     * @param int $post_id The post ID
     * @return array Array with 'title', 'description', and 'plugin' keys
     */
    private static function get_generic_meta( $post_id ) {
        $title = get_post_meta( $post_id, '_seo_title', true );
        $description = get_post_meta( $post_id, '_seo_description', true );
        
        // Fallback to post title if no SEO title set
        if ( empty( $title ) ) {
            $post = get_post( $post_id );
            if ( $post ) {
                $title = $post->post_title;
            }
        }
        
        return [
            'title'       => $title,
            'description' => $description,
            'plugin'      => 'generic',
        ];
    }
    
    /**
     * Get content excerpt for a post
     * Used by webhook dispatcher
     * 
     * @param WP_Post $post The post object
     * @param int     $length Maximum length in characters
     * @return string The excerpt
     */
    public static function get_content_excerpt( $post, $length = 300 ) {
        if ( ! $post instanceof WP_Post ) {
            return '';
        }
        
        // Use post excerpt if available
        if ( ! empty( $post->post_excerpt ) ) {
            $excerpt = $post->post_excerpt;
        } else {
            // Generate excerpt from content
            $excerpt = $post->post_content;
        }
        
        // Strip tags and shortcodes
        $excerpt = strip_shortcodes( $excerpt );
        $excerpt = wp_strip_all_tags( $excerpt );
        
        // Trim to length
        if ( strlen( $excerpt ) > $length ) {
            $excerpt = substr( $excerpt, 0, $length ) . '...';
        }
        
        return sanitize_textarea_field( $excerpt );
    }
}
