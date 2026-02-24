<?php
/**
 * Plugin Name:  Gemini-Kimi SEO Optimizer
 * Description:  Automated SEO A/B testing with n8n + AI
 * Version:      1.0.1
 * Requires PHP: 7.4
 * Requires WP:  5.8
 * Author:       Gemini-Kimi Team
 * License:      GPL v2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  gemini-kimi-seo
 * Domain Path:  /languages
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ─── Constants ───────────────────────────────────────────────────────────────
define( 'GKSO_VERSION',        '1.0.1' );
define( 'GKSO_PLUGIN_DIR',     plugin_dir_path( __FILE__ ) );
define( 'GKSO_PLUGIN_URL',     plugin_dir_url( __FILE__ ) );
define( 'GKSO_REST_NAMESPACE', 'gemini-kimi-seo/v1' );
define( 'GKSO_MIN_PHP',        '7.4' );
define( 'GKSO_MIN_WP',         '5.8' );
define( 'GKSO_LOG_FILE',       GKSO_PLUGIN_DIR . 'debug.log' );

// ─── Early bootstrap logger (available before autoload) ──────────────────────
if ( ! function_exists( 'gkso_log' ) ) {
    /**
     * Write a timestamped entry to the plugin debug log.
     *
     * @param string $message  Human-readable message.
     * @param mixed  $context  Optional data to JSON-encode.
     * @param string $level    ERROR | WARNING | INFO | DEBUG
     */
    function gkso_log( $message, $context = null, $level = 'INFO' ) {
        if ( ! defined( 'GKSO_LOG_FILE' ) ) {
            return;
        }
        $timestamp = date( 'Y-m-d H:i:s' );
        $entry     = "[{$timestamp}] [{$level}] {$message}";
        if ( null !== $context ) {
            $entry .= ' | ' . ( is_string( $context ) ? $context : wp_json_encode( $context ) );
        }
        $entry .= PHP_EOL;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( GKSO_LOG_FILE, $entry, FILE_APPEND | LOCK_EX );
    }
}

// ─── Global PHP error catcher → debug.log ────────────────────────────────────
set_error_handler( function( $errno, $errstr, $errfile, $errline ) {
    // Only log errors originating from this plugin
    if ( strpos( $errfile, GKSO_PLUGIN_DIR ) === false ) {
        return false; // Let WP handle it
    }
    $level = in_array( $errno, [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true )
        ? 'ERROR' : 'WARNING';
    gkso_log( "PHP #{$errno}: {$errstr} in {$errfile}:{$errline}", null, $level );
    return false; // Don't suppress default WP error handling
} );

// Catch fatal errors on shutdown
register_shutdown_function( function() {
    $error = error_get_last();
    if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
        if ( strpos( $error['file'], GKSO_PLUGIN_DIR ) !== false ) {
            gkso_log(
                "FATAL: {$error['message']} in {$error['file']}:{$error['line']}",
                null,
                'ERROR'
            );
        }
    }
} );

// ─── PSR-4-style Autoloader (classes live in /includes/) ─────────────────────
spl_autoload_register( function( $class ) {
    $prefix = 'GKSO_';
    $base   = GKSO_PLUGIN_DIR . 'includes/';

    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    // GKSO_Rest_Controller  → class-gkso-rest-controller.php
    $file = $base . 'class-' . strtolower(
        str_replace( [ $prefix, '_' ], [ '', '-' ], $class )
    ) . '.php';

    if ( file_exists( $file ) ) {
        gkso_log( "Autoloading class {$class} from {$file}", null, 'DEBUG' );
        require_once $file;
    } else {
        gkso_log( "Autoload MISS for class {$class} – expected {$file}", null, 'WARNING' );
    }
} );

// ─── Activation / Deactivation hooks ─────────────────────────────────────────
register_activation_hook( __FILE__, function() {
    gkso_log( 'Plugin activation started', null, 'INFO' );
    try {
        GKSO_Activator::activate();
        gkso_log( 'Plugin activation completed successfully', null, 'INFO' );
    } catch ( Throwable $e ) {
        gkso_log( 'Activation exception: ' . $e->getMessage(), $e->getTraceAsString(), 'ERROR' );
        // Re-throw so WP shows the fatal-error notice
        throw $e;
    }
} );

register_deactivation_hook( __FILE__, function() {
    gkso_log( 'Plugin deactivation', null, 'INFO' );
    GKSO_Deactivator::deactivate();
} );

// ─── Boot on plugins_loaded ───────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {

    // i18n
    load_plugin_textdomain( 'gemini-kimi-seo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // PHP version gate
    if ( version_compare( PHP_VERSION, GKSO_MIN_PHP, '<' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Gemini-Kimi SEO Optimizer</strong> requires PHP '
                . esc_html( GKSO_MIN_PHP ) . ' or higher. Current: ' . esc_html( PHP_VERSION ) . '</p></div>';
        } );
        gkso_log( 'Boot aborted – PHP version too low: ' . PHP_VERSION, null, 'ERROR' );
        return;
    }

    // WP version gate
    if ( version_compare( get_bloginfo( 'version' ), GKSO_MIN_WP, '<' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Gemini-Kimi SEO Optimizer</strong> requires WordPress '
                . esc_html( GKSO_MIN_WP ) . ' or higher.</p></div>';
        } );
        gkso_log( 'Boot aborted – WP version too low: ' . get_bloginfo( 'version' ), null, 'ERROR' );
        return;
    }

    gkso_log( 'Plugin booting (WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ')', null, 'INFO' );

    try {
        $admin = new GKSO_Admin();
        $rest  = new GKSO_Rest_Controller();
        $admin->init();
        $rest->init();
        GKSO_Internal_Linking::init();
        GKSO_Debug_Log::init();
        gkso_log( 'GKSO_Admin, GKSO_Rest_Controller, GKSO_Internal_Linking and GKSO_Debug_Log initialised', null, 'INFO' );
    } catch ( Throwable $e ) {
        gkso_log( 'Boot exception: ' . $e->getMessage(), $e->getTraceAsString(), 'ERROR' );
        add_action( 'admin_notices', function() use ( $e ) {
            echo '<div class="notice notice-error"><p><strong>Gemini-Kimi SEO Optimizer</strong> failed to load: '
                . esc_html( $e->getMessage() ) . '. Check the plugin debug log.</p></div>';
        } );
    }
} );
