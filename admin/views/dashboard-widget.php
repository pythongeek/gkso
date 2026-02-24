<?php
/**
 * Dashboard widget template
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Get aggregate stats
global $wpdb;

$enabled_post_types = get_option( 'gkso_enabled_post_types', [ 'post', 'page' ] );
$post_types_in = "'" . implode( "','", array_map( 'esc_sql', $enabled_post_types ) ) . "'";

// Count by status
$status_counts = [
    'Baseline'   => 0,
    'Testing'    => 0,
    'Optimized'  => 0,
    'Failed'     => 0,
];

foreach ( $status_counts as $status => $count ) {
    $status_counts[ $status ] = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
         JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
         WHERE pm.meta_key = %s 
         AND pm.meta_value = %s 
         AND p.post_type IN ({$post_types_in})
         AND p.post_status = 'publish'",
        GKSO_Meta_Schema::STATUS,
        $status
    ) );
}

// Total optimized count
$total_optimized = $status_counts['Optimized'];

// Currently testing count
$currently_testing = $status_counts['Testing'];

// Recent tests (last 7 days)
$recent_tests = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} 
     WHERE meta_key = %s 
     AND meta_value > %s",
    GKSO_Meta_Schema::STARTED,
    date( 'c', strtotime( '-7 days' ) )
) );

// Total tests ever run (based on version count)
$total_tests = $wpdb->get_var( $wpdb->prepare(
    "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} 
     WHERE meta_key = %s",
    GKSO_Meta_Schema::VERSION
) );

// Get posts currently in testing
$testing_posts = $wpdb->get_results( $wpdb->prepare(
    "SELECT p.ID, p.post_title, pm2.meta_value as started 
     FROM {$wpdb->postmeta} pm 
     JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
     LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
     WHERE pm.meta_key = %s 
     AND pm.meta_value = 'Testing'
     AND p.post_type IN ({$post_types_in})
     AND p.post_status = 'publish'
     ORDER BY pm2.meta_value DESC
     LIMIT 5",
    GKSO_Meta_Schema::STARTED,
    GKSO_Meta_Schema::STATUS
) );
?>

<div class="gkso-dashboard-widget">
    <!-- Stats Overview -->
    <div class="gkso-stats-grid">
        <div class="gkso-stat-box">
            <span class="gkso-stat-number"><?php echo intval( $total_optimized ); ?></span>
            <span class="gkso-stat-label"><?php esc_html_e( 'Optimized', 'gemini-kimi-seo' ); ?></span>
        </div>
        <div class="gkso-stat-box">
            <span class="gkso-stat-number"><?php echo intval( $currently_testing ); ?></span>
            <span class="gkso-stat-label"><?php esc_html_e( 'Testing', 'gemini-kimi-seo' ); ?></span>
        </div>
        <div class="gkso-stat-box">
            <span class="gkso-stat-number"><?php echo intval( $recent_tests ); ?></span>
            <span class="gkso-stat-label"><?php esc_html_e( 'This Week', 'gemini-kimi-seo' ); ?></span>
        </div>
        <div class="gkso-stat-box">
            <span class="gkso-stat-number"><?php echo intval( $total_tests ); ?></span>
            <span class="gkso-stat-label"><?php esc_html_e( 'Total Tests', 'gemini-kimi-seo' ); ?></span>
        </div>
    </div>
    
    <!-- Currently Testing -->
    <?php if ( ! empty( $testing_posts ) ) : ?>
        <div class="gkso-testing-section">
            <h4><?php esc_html_e( 'Currently Testing', 'gemini-kimi-seo' ); ?></h4>
            <ul class="gkso-testing-list">
                <?php foreach ( $testing_posts as $post ) : 
                    $progress = 0;
                    if ( ! empty( $post->started ) ) {
                        $test_duration = intval( get_option( 'gkso_test_duration_days', 14 ) );
                        $elapsed = time() - strtotime( $post->started );
                        $elapsed_days = floor( $elapsed / DAY_IN_SECONDS );
                        $progress = min( 100, round( ( $elapsed_days / $test_duration ) * 100 ) );
                    }
                ?>
                    <li>
                        <a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
                            <?php echo esc_html( $post->post_title ? $post->post_title : __( '(no title)', 'gemini-kimi-seo' ) ); ?>
                        </a>
                        <div class="gkso-mini-progress">
                            <div class="gkso-mini-progress-bar" style="width: <?php echo esc_attr( $progress ); ?>%;"></div>
                        </div>
                        <span class="gkso-progress-text"><?php echo esc_html( $progress ); ?>%</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <!-- Quick Links -->
    <div class="gkso-quick-links">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=gemini-kimi-seo' ) ); ?>" class="button">
            <?php esc_html_e( 'Settings', 'gemini-kimi-seo' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'edit.php?post_status=publish&post_type=post' ) ); ?>" class="button">
            <?php esc_html_e( 'View Posts', 'gemini-kimi-seo' ); ?>
        </a>
    </div>
</div>

<style>
.gkso-dashboard-widget {
    padding: 10px 0;
}

.gkso-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}

.gkso-stat-box {
    text-align: center;
    padding: 10px;
    background: #f0f0f0;
    border-radius: 4px;
}

.gkso-stat-number {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #23282d;
}

.gkso-stat-label {
    display: block;
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
}

.gkso-testing-section {
    margin-bottom: 20px;
}

.gkso-testing-section h4 {
    margin: 0 0 10px;
    font-size: 13px;
    text-transform: uppercase;
    color: #666;
}

.gkso-testing-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.gkso-testing-list li {
    display: flex;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.gkso-testing-list li:last-child {
    border-bottom: none;
}

.gkso-testing-list a {
    flex: 1;
    text-decoration: none;
}

.gkso-mini-progress {
    width: 60px;
    height: 6px;
    background: #ddd;
    border-radius: 3px;
    overflow: hidden;
    margin: 0 10px;
}

.gkso-mini-progress-bar {
    height: 100%;
    background: #ffc107;
    transition: width 0.3s ease;
}

.gkso-progress-text {
    font-size: 11px;
    color: #666;
    min-width: 30px;
    text-align: right;
}

.gkso-quick-links {
    display: flex;
    gap: 10px;
}

.gkso-quick-links .button {
    flex: 1;
    text-align: center;
}
</style>
