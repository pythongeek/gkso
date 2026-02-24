<?php
/**
 * GKSO_Notifications class
 * 
 * Handles Slack/email notifications for test events, errors, and weekly summaries.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_Notifications {
    
    /**
     * Initialize notification hooks
     */
    public static function init() {
        // Test completion
        add_action( 'gkso_test_completed', [ __CLASS__, 'notify_test_completed' ], 10, 3 );
        
        // Early termination
        add_action( 'gkso_early_termination', [ __CLASS__, 'notify_early_termination' ], 10, 2 );
        
        // Webhook failure
        add_action( 'gkso_webhook_permanently_failed', [ __CLASS__, 'notify_webhook_failure' ], 10, 2 );
        
        // IP blocked
        add_action( 'gkso_ip_blocked', [ __CLASS__, 'log_ip_blocked' ], 10, 2 );
        
        // Weekly summary
        add_action( 'gkso_weekly_summary', [ __CLASS__, 'send_weekly_summary' ] );
        
        // Schedule weekly summary
        if ( ! wp_next_scheduled( 'gkso_weekly_summary' ) ) {
            wp_schedule_event( strtotime( 'next sunday 09:00:00' ), 'weekly', 'gkso_weekly_summary' );
        }
    }
    
    /**
     * Send Slack notification
     * 
     * @param string $message The message to send
     * @return bool True on success
     */
    private static function send_slack( $message ) {
        $webhook_url = get_option( 'gkso_slack_webhook_url' );
        
        if ( empty( $webhook_url ) ) {
            return false;
        }
        
        $response = wp_remote_post( $webhook_url, [
            'method'      => 'POST',
            'timeout'     => 15,
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'body'        => wp_json_encode( [ 'text' => $message ] ),
            'sslverify'   => true,
        ] );
        
        return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
    }
    
    /**
     * Send email notification
     * 
     * @param string $subject The email subject
     * @param string $message The email message
     * @return bool True on success
     */
    private static function send_email( $subject, $message ) {
        $admin_email = get_option( 'admin_email' );
        
        return wp_mail(
            $admin_email,
            $subject,
            $message,
            [ 'Content-Type: text/html; charset=UTF-8' ]
        );
    }
    
    /**
     * Notify on test completion
     * 
     * @param int    $post_id    The post ID
     * @param string $decision   The decision (test_wins or baseline_wins)
     * @param array  $improvement The improvement data
     */
    public static function notify_test_completed( $post_id, $decision, $improvement ) {
        $post = get_post( $post_id );
        if ( ! $post ) return;
        
        $post_title = $post->post_title;
        $post_url = get_permalink( $post_id );
        $admin_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
        
        $ctr_pct = isset( $improvement['ctr_pct'] ) ? round( $improvement['ctr_pct'], 1 ) : 0;
        $position_change = isset( $improvement['position_abs'] ) ? round( $improvement['position_abs'], 1 ) : 0;
        
        $emoji = $decision === 'test_wins' ? '✅' : '⏹️';
        $result_text = $decision === 'test_wins' ? 'OPTIMIZED' : 'Baseline retained';
        
        // Slack notification
        $slack_message = sprintf(
            "%s SEO Test Complete: *%s*\n" .
            "Result: %s\n" .
            "CTR Change: %+.1f%%\n" .
            "Position Change: %+.1f places\n" .
            "Post: <%s|View> | <%s|Edit>",
            $emoji,
            $post_title,
            $result_text,
            $ctr_pct,
            $position_change,
            $post_url,
            $admin_url
        );
        
        self::send_slack( $slack_message );
        
        // Email notification for test wins
        if ( $decision === 'test_wins' ) {
            $email_subject = sprintf( '[GKSO] SEO Test Optimized: %s', $post_title );
            $email_message = sprintf(
                "<h2>SEO Test Complete - Optimization Successful</h2>\n" .
                "<p><strong>Post:</strong> %s</p>\n" .
                "<p><strong>CTR Improvement:</strong> +%.1f%%</p>\n" .
                "<p><strong>Position Change:</strong> +%.1f places</p>\n" .
                "<p><a href=\"%s\">View Post</a> | <a href=\"%s\">Edit Post</a></p>",
                esc_html( $post_title ),
                $ctr_pct,
                $position_change,
                esc_url( $post_url ),
                esc_url( $admin_url )
            );
            
            self::send_email( $email_subject, $email_message );
        }
    }
    
    /**
     * Notify on early termination
     * 
     * @param int    $post_id The post ID
     * @param string $reason  The termination reason
     */
    public static function notify_early_termination( $post_id, $reason ) {
        $post = get_post( $post_id );
        if ( ! $post ) return;
        
        $post_title = $post->post_title;
        $admin_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
        
        // Slack notification
        $slack_message = sprintf(
            "⚠️ SEO Test Early Terminated: *%s*\n" .
            "Reason: %s\n" .
            "<%s|Edit Post>",
            $post_title,
            $reason,
            $admin_url
        );
        
        self::send_slack( $slack_message );
        
        // Email notification
        $email_subject = sprintf( '[GKSO] URGENT: SEO Test Terminated - %s', $post_title );
        $email_message = sprintf(
            "<h2>SEO Test Early Termination</h2>\n" .
            "<p><strong>Post:</strong> %s</p>\n" .
            "<p><strong>Reason:</strong> %s</p>\n" .
            "<p><a href=\"%s\">Edit Post</a></p>",
            esc_html( $post_title ),
            esc_html( $reason ),
            esc_url( $admin_url )
        );
        
        self::send_email( $email_subject, $email_message );
    }
    
    /**
     * Notify on webhook permanent failure
     * 
     * @param int    $post_id The post ID
     * @param string $error   The error message
     */
    public static function notify_webhook_failure( $post_id, $error ) {
        $post = get_post( $post_id );
        $post_title = $post ? $post->post_title : 'Unknown';
        $admin_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
        
        // Slack notification
        $slack_message = sprintf(
            "🚨 CRITICAL: n8n Webhook Failed Permanently\n" .
            "Post: %s\n" .
            "Error: %s\n" .
            "<%s|Investigate Now>",
            $post_title,
            $error,
            $admin_url
        );
        
        self::send_slack( $slack_message );
        
        // Email notification
        $email_subject = '[GKSO] CRITICAL: Webhook Failure - Manual Action Required';
        $email_message = sprintf(
            "<h2>n8n Webhook Permanently Failed</h2>\n" .
            "<p><strong>Post:</strong> %s</p>\n" .
            "<p><strong>Error:</strong> %s</p>\n" .
            "<p><a href=\"%s\">Investigate</a></p>\n" .
            "<p>Please check your n8n instance and WordPress webhook configuration.</p>",
            esc_html( $post_title ),
            esc_html( $error ),
            esc_url( $admin_url )
        );
        
        self::send_email( $email_subject, $email_message );
    }
    
    /**
     * Log IP blocked event
     * 
     * @param string $ip      The blocked IP
     * @param string $request The request details
     */
    public static function log_ip_blocked( $ip, $request ) {
        // Get existing log
        $log = get_option( 'gkso_security_log', [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        
        // Add new entry
        $log[] = [
            'timestamp' => current_time( 'c' ),
            'ip'        => $ip,
            'request'   => $request,
            'action'    => 'blocked',
        ];
        
        // Keep only last 100 entries
        if ( count( $log ) > 100 ) {
            $log = array_slice( $log, -100 );
        }
        
        update_option( 'gkso_security_log', $log );
        
        // Check for brute force (5 blocks from same IP in 1 hour)
        $recent_blocks = array_filter( $log, function( $entry ) use ( $ip ) {
            if ( $entry['ip'] !== $ip || $entry['action'] !== 'blocked' ) {
                return false;
            }
            $entry_time = strtotime( $entry['timestamp'] );
            return ( time() - $entry_time ) <= HOUR_IN_SECONDS;
        } );
        
        if ( count( $recent_blocks ) >= 5 ) {
            // Send security alert
            $email_subject = '[GKSO] SECURITY ALERT: Potential Brute Force Attack';
            $email_message = sprintf(
                "<h2>Security Alert</h2>\n" .
                "<p>IP address <strong>%s</strong> has been blocked 5+ times in the last hour.</p>\n" .
                "<p>Consider adding this IP to your firewall blocklist.</p>",
                esc_html( $ip )
            );
            
            self::send_email( $email_subject, $email_message );
        }
    }
    
    /**
     * Send weekly summary email
     */
    public static function send_weekly_summary() {
        global $wpdb;
        
        // Get stats for last 7 days
        $last_week = date( 'c', strtotime( '-7 days' ) );
        
        // Tests run
        $tests_run = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = %s 
             AND meta_value > %s",
            GKSO_Meta_Schema::STARTED,
            $last_week
        ) );
        
        // Completed tests
        $history = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} 
             WHERE meta_key = %s",
            GKSO_Meta_Schema::TEST_HISTORY
        ) );
        
        $completed = 0;
        $successful = 0;
        $top_improvements = [];
        $failures = [];
        
        foreach ( $history as $row ) {
            $records = json_decode( $row->meta_value, true );
            if ( ! is_array( $records ) ) continue;
            
            foreach ( $records as $record ) {
                if ( empty( $record['completed_at'] ) ) continue;
                if ( strtotime( $record['completed_at'] ) < strtotime( $last_week ) ) continue;
                
                $completed++;
                
                if ( $record['result'] === 'optimized' ) {
                    $successful++;
                    $top_improvements[] = $record;
                } elseif ( $record['result'] === 'failed' ) {
                    $failures[] = $record;
                }
            }
        }
        
        // Sort improvements by CTR
        usort( $top_improvements, function( $a, $b ) {
            $a_ctr = $a['metrics']['improvement']['ctr'] ?? 0;
            $b_ctr = $b['metrics']['improvement']['ctr'] ?? 0;
            return $b_ctr <=> $a_ctr;
        } );
        
        // Get pending queue
        $pending = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = %s 
             AND meta_value = %s",
            GKSO_Meta_Schema::STATUS,
            'Testing'
        ) );
        
        // Build email
        $success_rate = $completed > 0 ? round( ( $successful / $completed ) * 100, 1 ) : 0;
        
        $email_subject = '[GKSO] Weekly Summary - ' . date( 'F j, Y' );
        $email_message = sprintf(
            "<h2>GKSO Weekly Summary</h2>\n" .
            "<p><strong>Week:</strong> %s - %s</p>\n" .
            "<hr>\n" .
            "<h3>Stats</h3>\n" .
            "<ul>\n" .
            "<li>Tests Started: %d</li>\n" .
            "<li>Tests Completed: %d</li>\n" .
            "<li>Success Rate: %.1f%%</li>\n" .
            "<li>Pending Queue: %d</li>\n" .
            "</ul>\n",
            date( 'M j', strtotime( '-7 days' ) ),
            date( 'M j' ),
            $tests_run,
            $completed,
            $success_rate,
            $pending
        );
        
        // Top 3 improvements
        if ( ! empty( $top_improvements ) ) {
            $email_message .= "<h3>Top Improvements</h3>\n<ol>\n";
            foreach ( array_slice( $top_improvements, 0, 3 ) as $improvement ) {
                $ctr = ( $improvement['metrics']['improvement']['ctr'] ?? 0 ) * 100;
                $email_message .= sprintf(
                    "<li>Test %s: +%.1f%% CTR</li>\n",
                    esc_html( $improvement['test_id'] ),
                    $ctr
                );
            }
            $email_message .= "</ol>\n";
        }
        
        // Failures
        if ( ! empty( $failures ) ) {
            $email_message .= "<h3>Failures</h3>\n<ul>\n";
            foreach ( array_slice( $failures, 0, 3 ) as $failure ) {
                $email_message .= sprintf(
                    "<li>Test %s: %s</li>\n",
                    esc_html( $failure['test_id'] ),
                    esc_html( $failure['failure_reason'] ?? 'Unknown' )
                );
            }
            $email_message .= "</ul>\n";
        }
        
        self::send_email( $email_subject, $email_message );
    }
}

// Initialize notifications
add_action( 'plugins_loaded', [ 'GKSO_Notifications', 'init' ] );
