<?php
/**
 * GKSO Debug Log Viewer
 *
 * Adds a "Debug Log" submenu under SEO Optimizer (admins only).
 * Displays, clears, and allows download of debug.log.
 *
 * Registered via gkso_register_debug_page() called from the main plugin file.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GKSO_Debug_Log {

    /** Absolute path to the log file */
    const LOG_FILE = GKSO_LOG_FILE;

    /** Maximum lines to show in the viewer */
    const MAX_LINES = 500;

    /**
     * Register the submenu page and handle actions.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'handle_actions' ] );
    }

    /**
     * Add "Debug Log" submenu under the top-level SEO Optimizer menu.
     */
    public static function register_page() {
        add_submenu_page(
            'gkso-dashboard',
            __( 'Debug Log', 'gemini-kimi-seo' ),
            __( 'Debug Log', 'gemini-kimi-seo' ),
            'manage_options',
            'gkso-debug-log',
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * Handle clear / download actions before any output.
     */
    public static function handle_actions() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'gkso-debug-log' ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // ── Clear log ────────────────────────────────────────────────────────
        if ( isset( $_POST['gkso_clear_log'] ) ) {
            check_admin_referer( 'gkso_debug_log_action' );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( self::LOG_FILE, '' );
            gkso_log( 'Debug log cleared by admin user #' . get_current_user_id(), null, 'INFO' );
            wp_safe_redirect( add_query_arg( 'gkso_cleared', '1', menu_page_url( 'gkso-debug-log', false ) ) );
            exit;
        }

        // ── Download log ─────────────────────────────────────────────────────
        if ( isset( $_GET['gkso_download_log'] ) ) {
            check_admin_referer( 'gkso_download_log' );
            if ( file_exists( self::LOG_FILE ) ) {
                $filename = 'gkso-debug-' . date( 'Y-m-d-His' ) . '.log';
                header( 'Content-Description: File Transfer' );
                header( 'Content-Type: text/plain' );
                header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
                header( 'Content-Length: ' . filesize( self::LOG_FILE ) );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
                readfile( self::LOG_FILE );
                exit;
            }
        }
    }

    /**
     * Render the debug log admin page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'gemini-kimi-seo' ) );
        }

        $log_exists   = file_exists( self::LOG_FILE );
        $log_size     = $log_exists ? filesize( self::LOG_FILE ) : 0;
        $log_contents = '';
        $total_lines  = 0;

        if ( $log_exists && $log_size > 0 ) {
            // Read last MAX_LINES lines efficiently
            $lines = self::tail( self::LOG_FILE, self::MAX_LINES );
            $total_lines  = $lines['total'];
            $log_contents = implode( '', $lines['lines'] );
        }

        $download_url = wp_nonce_url(
            add_query_arg( [ 'page' => 'gkso-debug-log', 'gkso_download_log' => '1' ], admin_url( 'admin.php' ) ),
            'gkso_download_log'
        );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Gemini-Kimi SEO – Debug Log', 'gemini-kimi-seo' ); ?></h1>

            <?php if ( ! empty( $_GET['gkso_cleared'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log cleared.', 'gemini-kimi-seo' ); ?></p></div>
            <?php endif; ?>

            <p>
                <strong><?php esc_html_e( 'Log file:', 'gemini-kimi-seo' ); ?></strong>
                <code><?php echo esc_html( self::LOG_FILE ); ?></code>
                &nbsp;|&nbsp;
                <strong><?php esc_html_e( 'Size:', 'gemini-kimi-seo' ); ?></strong>
                <?php echo esc_html( self::format_bytes( $log_size ) ); ?>
                <?php if ( $total_lines > self::MAX_LINES ) : ?>
                    &nbsp;|&nbsp;<em><?php printf( esc_html__( 'Showing last %d of %d lines.', 'gemini-kimi-seo' ), self::MAX_LINES, $total_lines ); ?></em>
                <?php endif; ?>
            </p>

            <div style="display:flex;gap:8px;margin-bottom:12px;">
                <?php if ( $log_exists && $log_size > 0 ) : ?>
                    <a href="<?php echo esc_url( $download_url ); ?>" class="button">
                        ⬇ <?php esc_html_e( 'Download Log', 'gemini-kimi-seo' ); ?>
                    </a>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field( 'gkso_debug_log_action' ); ?>
                    <button type="submit" name="gkso_clear_log" class="button button-secondary"
                            onclick="return confirm('<?php esc_attr_e( 'Clear the entire log file?', 'gemini-kimi-seo' ); ?>')">
                        🗑 <?php esc_html_e( 'Clear Log', 'gemini-kimi-seo' ); ?>
                    </button>
                </form>

                <button class="button" onclick="location.reload()">↺ <?php esc_html_e( 'Refresh', 'gemini-kimi-seo' ); ?></button>
            </div>

            <div style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;
                        line-height:1.6;padding:16px;border-radius:4px;overflow:auto;
                        max-height:600px;white-space:pre-wrap;word-break:break-all;">
                <?php if ( $log_contents ) :
                    // Colorise log levels
                    $html = esc_html( $log_contents );
                    $html = preg_replace( '/\[ERROR\]/',   '<span style="color:#f44747">[ERROR]</span>',   $html );
                    $html = preg_replace( '/\[WARNING\]/', '<span style="color:#ce9178">[WARNING]</span>', $html );
                    $html = preg_replace( '/\[INFO\]/',    '<span style="color:#9cdcfe">[INFO]</span>',    $html );
                    $html = preg_replace( '/\[DEBUG\]/',   '<span style="color:#6a9955">[DEBUG]</span>',   $html );
                    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                else : ?>
                    <span style="color:#888"><?php esc_html_e( '— Log is empty —', 'gemini-kimi-seo' ); ?></span>
                <?php endif; ?>
            </div>

            <p style="margin-top:8px;color:#888;font-size:11px;">
                <?php printf(
                    /* translators: %s: path to file */
                    esc_html__( 'This log is stored at %s and is not publicly accessible.', 'gemini-kimi-seo' ),
                    '<code>' . esc_html( self::LOG_FILE ) . '</code>'
                ); ?>
            </p>
        </div>
        <script>
        // Auto-scroll to bottom
        (function(){
            var el = document.querySelector('[style*="background:#1e1e1e"]');
            if(el) el.scrollTop = el.scrollHeight;
        })();
        </script>
        <?php
    }

    /**
     * Read the last $n lines of a file without loading the whole thing.
     *
     * @param  string $file     Absolute file path.
     * @param  int    $n        Number of lines to retrieve.
     * @return array  { lines: string[], total: int }
     */
    private static function tail( $file, $n ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $all = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( false === $all ) {
            return [ 'lines' => [], 'total' => 0 ];
        }
        $total = count( $all );
        $slice = array_slice( $all, -$n );
        // Re-add newlines
        $slice = array_map( fn( $l ) => $l . PHP_EOL, $slice );
        return [ 'lines' => $slice, 'total' => $total ];
    }

    /**
     * Human-readable file size.
     */
    private static function format_bytes( $bytes ) {
        if ( $bytes < 1024 ) { return $bytes . ' B'; }
        if ( $bytes < 1048576 ) { return round( $bytes / 1024, 1 ) . ' KB'; }
        return round( $bytes / 1048576, 1 ) . ' MB';
    }
}
