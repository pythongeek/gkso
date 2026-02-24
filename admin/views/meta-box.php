<?php
/**
 * Meta box template for SEO A/B Test Status
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$post_id = $post->ID;
$status = GKSO_State_Machine::get_status( $post_id );
$version = intval( GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::VERSION, 0 ) );
$history = GKSO_Meta_Schema::get_history( $post_id );

// Status badge colors
$status_colors = [
    'Baseline'   => '#6c757d',
    'Testing'    => '#ffc107',
    'Optimized'  => '#28a745',
    'Failed'     => '#dc3545',
];

$status_color = $status_colors[ $status ] ?? '#6c757d';

// Get test details if Testing
$test_title = '';
$ai_model = '';
$started = '';
$progress_percent = 0;
$estimated_completion = '';

if ( $status === 'Testing' ) {
    $test_title = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_TITLE );
    $ai_model = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TEST_AI_MODEL );
    $started = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::STARTED );
    
    if ( ! empty( $started ) ) {
        $started_timestamp = strtotime( $started );
        $elapsed_seconds = time() - $started_timestamp;
        $elapsed_days = floor( $elapsed_seconds / DAY_IN_SECONDS );
        
        $test_duration = intval( get_option( 'gkso_test_duration_days', 14 ) );
        $progress_percent = min( 100, round( ( $elapsed_days / $test_duration ) * 100, 2 ) );
        
        $estimated_completion = date_i18n( get_option( 'date_format' ), strtotime( "+{$test_duration} days", $started_timestamp ) );
    }
}

// Get last test info if Optimized or Failed
$last_test = null;
if ( in_array( $status, [ 'Optimized', 'Failed' ], true ) && ! empty( $history ) ) {
    $last_test = end( $history );
}

// Get failure reason if Failed
$failure_reason = '';
if ( $status === 'Failed' ) {
    $failure_reason = GKSO_Meta_Schema::get_meta( $post_id, GKSO_Meta_Schema::TERMINATION_REASON );
}

wp_nonce_field( 'gkso_action', 'gkso_nonce' );
?>

<div class="gkso-meta-box">
    <!-- Status Badge -->
    <div class="gkso-status-section">
        <span class="gkso-status-badge" style="background-color: <?php echo esc_attr( $status_color ); ?>;">
            <?php echo esc_html( $status ); ?>
        </span>
        <?php if ( $version > 0 ) : ?>
            <span class="gkso-version">v<?php echo intval( $version ); ?></span>
        <?php endif; ?>
    </div>
    
    <?php if ( $status === 'Testing' ) : ?>
        <!-- Testing Progress -->
        <div class="gkso-testing-section">
            <h4><?php esc_html_e( 'Test in Progress', 'gemini-kimi-seo' ); ?></h4>
            
            <?php if ( ! empty( $test_title ) ) : ?>
                <div class="gkso-test-title">
                    <strong><?php esc_html_e( 'Testing Title:', 'gemini-kimi-seo' ); ?></strong>
                    <p><?php echo esc_html( $test_title ); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ( ! empty( $ai_model ) ) : ?>
                <div class="gkso-ai-model">
                    <strong><?php esc_html_e( 'AI Model:', 'gemini-kimi-seo' ); ?></strong>
                    <span><?php echo esc_html( ucfirst( $ai_model ) ); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ( ! empty( $started ) ) : ?>
                <div class="gkso-progress-section">
                    <div class="gkso-progress-bar">
                        <div class="gkso-progress-fill" style="width: <?php echo esc_attr( $progress_percent ); ?>%;"></div>
                    </div>
                    <div class="gkso-progress-text">
                        <?php echo esc_html( $progress_percent ); ?>% - 
                        <?php 
                        printf(
                            /* translators: %s: Estimated completion date */
                            esc_html__( 'Est. completion: %s', 'gemini-kimi-seo' ),
                            esc_html( $estimated_completion )
                        ); 
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <?php if ( $status === 'Optimized' && $last_test ) : ?>
        <!-- Optimized Results -->
        <div class="gkso-optimized-section">
            <h4><?php esc_html_e( 'Last Test Results', 'gemini-kimi-seo' ); ?></h4>
            
            <?php if ( ! empty( $last_test['metrics']['improvement'] ) ) : ?>
                <div class="gkso-improvement">
                    <?php if ( isset( $last_test['metrics']['improvement']['ctr'] ) ) : ?>
                        <div class="gkso-metric">
                            <span class="gkso-metric-label"><?php esc_html_e( 'CTR Improvement:', 'gemini-kimi-seo' ); ?></span>
                            <span class="gkso-metric-value gkso-positive">
                                +<?php echo esc_html( round( $last_test['metrics']['improvement']['ctr'] * 100, 2 ) ); ?>%
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ( isset( $last_test['metrics']['improvement']['position'] ) ) : ?>
                        <div class="gkso-metric">
                            <span class="gkso-metric-label"><?php esc_html_e( 'Position Change:', 'gemini-kimi-seo' ); ?></span>
                            <span class="gkso-metric-value gkso-positive">
                                <?php echo esc_html( round( $last_test['metrics']['improvement']['position'], 2 ) ); ?> 
                                <?php esc_html_e( 'positions', 'gemini-kimi-seo' ); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <a href="#" class="gkso-view-history"><?php esc_html_e( 'View Full History', 'gemini-kimi-seo' ); ?></a>
        </div>
    <?php endif; ?>
    
    <?php if ( $status === 'Failed' ) : ?>
        <!-- Failed Status -->
        <div class="gkso-failed-section">
            <h4><?php esc_html_e( 'Test Failed', 'gemini-kimi-seo' ); ?></h4>
            
            <?php if ( ! empty( $failure_reason ) ) : ?>
                <div class="gkso-failure-reason">
                    <strong><?php esc_html_e( 'Reason:', 'gemini-kimi-seo' ); ?></strong>
                    <p><?php echo esc_html( $failure_reason ); ?></p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <?php if ( ! empty( $history ) ) : ?>
        <!-- Test History -->
        <div class="gkso-history-section">
            <h4><?php esc_html_e( 'Recent History', 'gemini-kimi-seo' ); ?></h4>
            <table class="gkso-history-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Version', 'gemini-kimi-seo' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'gemini-kimi-seo' ); ?></th>
                        <th><?php esc_html_e( 'Result', 'gemini-kimi-seo' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $recent_history = array_slice( array_reverse( $history ), 0, 5 );
                    foreach ( $recent_history as $record ) : 
                        $result_label = isset( $record['result'] ) ? $record['result'] : 'unknown';
                        $result_class = '';
                        
                        switch ( $result_label ) {
                            case 'optimized':
                            case 'test_wins':
                                $result_class = 'gkso-result-success';
                                break;
                            case 'baseline':
                            case 'baseline_wins':
                                $result_class = 'gkso-result-neutral';
                                break;
                            case 'failed':
                            case 'expired':
                                $result_class = 'gkso-result-failed';
                                break;
                        }
                    ?>
                        <tr>
                            <td>v<?php echo intval( $record['version'] ?? 0 ); ?></td>
                            <td>
                                <?php 
                                if ( ! empty( $record['completed_at'] ) ) {
                                    echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $record['completed_at'] ) ) );
                                } else {
                                    echo '-'; 
                                }
                                ?>
                            </td>
                            <td>
                                <span class="gkso-result-badge <?php echo esc_attr( $result_class ); ?>">
                                    <?php echo esc_html( ucfirst( $result_label ) ); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    
    <!-- Action Buttons -->
    <div class="gkso-actions">
        <?php 
        $can_initiate = GKSO_State_Machine::can_initiate_test( $post_id );
        $can_initiate = ! is_wp_error( $can_initiate );
        ?>
        
        <?php if ( $can_initiate ) : ?>
            <button type="button" class="button button-primary gkso-start-test" data-post-id="<?php echo intval( $post_id ); ?>">
                <?php esc_html_e( 'Start New Test', 'gemini-kimi-seo' ); ?>
            </button>
        <?php endif; ?>
        
        <?php if ( $status === 'Testing' ) : ?>
            <button type="button" class="button gkso-stop-test" data-post-id="<?php echo intval( $post_id ); ?>">
                <?php esc_html_e( 'Stop & Revert', 'gemini-kimi-seo' ); ?>
            </button>
        <?php endif; ?>
        
        <a href="<?php echo esc_url( rest_url( GKSO_REST_NAMESPACE . '/test-status/' . $post_id ) ); ?>" target="_blank" class="button">
            <?php esc_html_e( 'View Details', 'gemini-kimi-seo' ); ?>
        </a>
    </div>
    
    <div class="gkso-spinner" style="display: none;">
        <span class="spinner is-active"></span>
        <span class="gkso-spinner-text"></span>
    </div>
    
    <div class="gkso-message" style="display: none;"></div>
</div>
