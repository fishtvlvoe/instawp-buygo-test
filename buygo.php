<?php
/**
 * Plugin Name:       BuyGo
 * Plugin URI:        https://buygo.me
 * Description:       BuyGo Core Plugin with Plus One Module. Manage roles, permissions, API settings, sync, and LINE +1 order flow.
 * Version:           0.0.5
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            BuyGo Team
 * Author URI:        https://buygo.me
 * License:           GPL v2 or later
 * Text Domain:       buygo
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BUYGO_PLUGIN_FILE', __FILE__ );

define( 'BUYGO_RP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUYGO_RP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BUYGO_RP_VERSION', '0.0.5' );
define( 'MYGO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload Dependencies
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Fail gracefully if composer install hasn't been run
    add_action('admin_notices', function() {
        echo '<div class="error"><p>BuyGo：Composer 依賴缺失，請先在外掛目錄執行 "composer install"。</p></div>';
    });
    return;
}

// Initialize Global Functions
if ( file_exists( __DIR__ . '/includes/functions.php' ) ) {
    require_once __DIR__ . '/includes/functions.php';
}

// Initialize the Core App
add_action( 'plugins_loaded', function() {
    \BuyGo\Core\App::instance()->run();
}, 0 ); // Priority 0 to load before other BuyGo plugins

// [FIX] Register LINE Webhook REST API routes
// Directly register routes instead of relying on Plugin class initialization
add_action('rest_api_init', function() {
    // LINE Webhook
    register_rest_route('buygo/v1', '/line-webhook', [
        'methods' => 'POST',
        'callback' => function(\WP_REST_Request $request) {
            // Use LineWebhookHandler directly
            if (class_exists('\BuyGo\Core\Services\LineWebhookHandler')) {
                try {
                    $handler = new \BuyGo\Core\Services\LineWebhookHandler();
                    return $handler->handleWebhook($request);
                } catch (\Exception $e) {
                    error_log('LINE Webhook Error: ' . $e->getMessage());
                    return new \WP_Error('webhook_error', $e->getMessage(), ['status' => 500]);
                }
            }
            return new \WP_Error('not_found', 'Handler not found', ['status' => 404]);
        },
        'permission_callback' => '__return_true',
    ]);

    // LINE Login Callback
    register_rest_route('buygo/v1', '/line-callback', [
        'methods' => 'GET',
        'callback' => function(\WP_REST_Request $request) {
            if (class_exists('\BuyGo\Core\Core\Plugin')) {
                $plugin = \BuyGo\Core\Core\Plugin::getInstance();
                return $plugin->handleLineCallback($request);
            }
            return new \WP_Error('not_found', 'Handler not found', ['status' => 404]);
        },
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Cleanup on deactivation.
 *
 * [AI Note]
 * - Since Plus One is now a module, we clear its legacy cron hooks here (single plugin entry).
 */
register_deactivation_hook( __FILE__, function() {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'buygo_line_fc_check_expired_orders' );

		wp_clear_scheduled_hook( 'buygo_plus_one_check_expired_orders' );
	}
} );

// Boot legacy modules (e.g. Plus One) inside this single plugin.
$plus_one_bootstrap = __DIR__ . '/modules/plus-one/bootstrap.php';
if ( file_exists( $plus_one_bootstrap ) ) {
	require_once $plus_one_bootstrap;
}

// Boot Frontend Portal module
$frontend_portal_bootstrap = __DIR__ . '/modules/frontend-portal/bootstrap.php';
if ( file_exists( $frontend_portal_bootstrap ) ) {
	require_once $frontend_portal_bootstrap;
}

// Initialize Custom Cron Jobs
$cleanup_cron_file = __DIR__ . '/includes/cron/mygo-cleanup-cron.php';
if ( file_exists( $cleanup_cron_file ) ) {
    require_once $cleanup_cron_file;
}
