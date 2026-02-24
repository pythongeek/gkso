<?php
/**
 * Settings page template
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Get current settings
$webhook_url = get_option( 'gkso_n8n_webhook_url', '' );
$daily_limit = get_option( 'gkso_daily_test_limit_per_user', 10 );
$test_duration = get_option( 'gkso_test_duration_days', 14 );
$cooldown_days = get_option( 'gkso_cooldown_days', 30 );
$enabled_post_types = get_option( 'gkso_enabled_post_types', [ 'post', 'page' ] );
$enable_ip_allowlist = get_option( 'gkso_enable_ip_allowlist', false );
$ip_allowlist = get_option( 'gkso_n8n_ip_allowlist', [] );
$shared_secret = get_option( 'gkso_shared_secret', '' );

// Get all public post types
$post_types = get_post_types( [ 'public' => true ], 'objects' );
?>

<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    
    <?php settings_errors( 'gkso_settings' ); ?>
    
    <form method="post" action="">
        <?php wp_nonce_field( 'gkso_settings', 'gkso_settings_nonce' ); ?>
        
        <h2><?php esc_html_e( 'n8n Integration', 'gemini-kimi-seo' ); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="gkso_n8n_webhook_url"><?php esc_html_e( 'Webhook URL', 'gemini-kimi-seo' ); ?></label>
                </th>
                <td>
                    <input type="url" 
                           name="gkso_n8n_webhook_url" 
                           id="gkso_n8n_webhook_url" 
                           value="<?php echo esc_attr( $webhook_url ); ?>" 
                           class="regular-text"
                           placeholder="https://your-n8n-instance.com/webhook/...">
                    <p class="description">
                        <?php esc_html_e( 'The n8n webhook URL that will receive test initiation requests.', 'gemini-kimi-seo' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="submit" name="gkso_test_webhook" class="button">
                <?php esc_html_e( 'Test Connection', 'gemini-kimi-seo' ); ?>
            </button>
        </p>
        
        <h2><?php esc_html_e( 'Security', 'gemini-kimi-seo' ); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="gkso_shared_secret"><?php esc_html_e( 'Shared Secret', 'gemini-kimi-seo' ); ?></label>
                </th>
                <td>
                    <code id="gkso_shared_secret" class="gkso-secret"><?php echo esc_html( substr( $shared_secret, 0, 8 ) . '...' ); ?></code>
                    <p class="description">
                        <?php esc_html_e( 'Used to sign webhook requests. Copy this to your n8n workflow.', 'gemini-kimi-seo' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="gkso_rotate_secret"><?php esc_html_e( 'Rotate Secret', 'gemini-kimi-seo' ); ?></label>
                </th>
                <td>
                    <button type="submit" name="gkso_rotate_secret" class="button" onclick="return confirm('<?php esc_attr_e( 'Are you sure? The old secret will be valid for 1 hour.', 'gemini-kimi-seo' ); ?>');">
                        <?php esc_html_e( 'Generate New Secret', 'gemini-kimi-seo' ); ?>
                    </button>
                    <p class="description">
                        <?php esc_html_e( 'Generate a new shared secret. Previous secret remains valid for 1 hour.', 'gemini-kimi-seo' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="gkso_enable_ip_allowlist"><?php esc_html_e( 'Enable IP Allowlist', 'gemini-kimi-seo' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" 
                           name="gkso_enable_ip_allowlist" 
                           id="gkso_enable_ip_allowlist" 
                           value="1"
                           <?php checked( $enable_ip_allowlist ); ?>>
                    <label for="gkso_enable_ip_allowlist">
                        <?php esc_html_e( 'Only accept requests from allowed IPs', 'gemini-kimi-seo' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="gkso_ip_allowlist"><?php esc_html_e( 'IP Allowlist', 'gemini-kimi-seo' ); ?></label>
                </th>
                <td>
                    <textarea name="gkso_ip_allowlist" 
                              id="gkso_ip_allowlist" 
                              rows="5" 
                              class="large-text code"
                              placeholder="192.168.1.1&#10;10.0.0.1"><?php echo esc_textarea( implode( "\n", $ip_allowlist ) ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Enter one IP address per line. Only these IPs will be allowed to send callbacks.', 'gemini-kimi-seo' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e( 'Test Settings', 'gemini-kimi-seo' ); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="gkso_daily_test_limit"><?php esc_html_e( 'Daily Test Limit per User', 'gemini-kimi-seo' ); ?></label>
                </th>
                <td>
                    <input type="number" 
                           name="gkso_daily_test_limit" 
                           id="gkso_daily_test_limit" 
                           value="<?php echo intval( $daily_limit ); ?>" 
                           min="1" 
                           max="100"
                           class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Maximum number of tests a user can initiate per day.', 'gemini-kimi-seo' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="gkso_test_duration"><?php esc_html_e( 'Test Duration (days)', 'gemini-kimi-seo' ); ?></label>
                </th>
                <td>
                    <input type="number" 
                           name="gkso_test_duration" 
                           id="gkso_test_duration" 
                           value="<?php echo intval( $test_duration ); ?>" 
                           min="1" 
                           max="30"
                           class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'How long each A/B test runs before a winner is chosen.', 'gemini-kimi-seo' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="gkso_cooldown_days"><?php esc_html_e( 'Cooldown Period (days)', 'gemini-kimi-seo' ); ?></label>
                </th>
                <td>
                    <input type="number" 
                           name="gkso_cooldown_days" 
                           id="gkso_cooldown_days" 
                           value="<?php echo intval( $cooldown_days ); ?>" 
                           min="0" 
                           max="90"
                           class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'How long to wait after a successful optimization before starting a new test.', 'gemini-kimi-seo' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e( 'Post Types', 'gemini-kimi-seo' ); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enabled Post Types', 'gemini-kimi-seo' ); ?></th>
                <td>
                    <?php foreach ( $post_types as $pt ) : ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" 
                                   name="gkso_enabled_post_types[]" 
                                   value="<?php echo esc_attr( $pt->name ); ?>"
                                   <?php checked( in_array( $pt->name, $enabled_post_types, true ) ); ?>>
                            <?php echo esc_html( $pt->label ); ?> (<?php echo esc_html( $pt->name ); ?>)
                        </label>
                    <?php endforeach; ?>
                    <p class="description">
                        <?php esc_html_e( 'Select which post types should have the SEO A/B Test meta box.', 'gemini-kimi-seo' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <?php submit_button( __( 'Save Settings', 'gemini-kimi-seo' ), 'primary', 'gkso_save_settings' ); ?>
    </form>
    
    <h2><?php esc_html_e( 'REST API Endpoints', 'gemini-kimi-seo' ); ?></h2>
    
    <table class="widefat">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Method', 'gemini-kimi-seo' ); ?></th>
                <th><?php esc_html_e( 'Endpoint', 'gemini-kimi-seo' ); ?></th>
                <th><?php esc_html_e( 'Description', 'gemini-kimi-seo' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>POST</code></td>
                <td><code><?php echo esc_html( GKSO_REST_NAMESPACE ); ?>/initiate-test</code></td>
                <td><?php esc_html_e( 'Start a new SEO A/B test', 'gemini-kimi-seo' ); ?></td>
            </tr>
            <tr>
                <td><code>POST</code></td>
                <td><code><?php echo esc_html( GKSO_REST_NAMESPACE ); ?>/update-meta</code></td>
                <td><?php esc_html_e( 'n8n callback to update SEO meta', 'gemini-kimi-seo' ); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code><?php echo esc_html( GKSO_REST_NAMESPACE ); ?>/test-status/{post_id}</code></td>
                <td><?php esc_html_e( 'Get test status for a post', 'gemini-kimi-seo' ); ?></td>
            </tr>
            <tr>
                <td><code>POST</code></td>
                <td><code><?php echo esc_html( GKSO_REST_NAMESPACE ); ?>/early-terminate</code></td>
                <td><?php esc_html_e( 'Stop a test early', 'gemini-kimi-seo' ); ?></td>
            </tr>
        </tbody>
    </table>
</div>

<style>
.gkso-secret {
    background: #f0f0f0;
    padding: 5px 10px;
    border-radius: 3px;
    font-family: monospace;
}
</style>
